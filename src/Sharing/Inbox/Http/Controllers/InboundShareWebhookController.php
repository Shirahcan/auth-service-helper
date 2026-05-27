<?php

namespace AuthService\Helper\Sharing\Inbox\Http\Controllers;

use AuthService\Helper\Sharing\Envelope\Exceptions\InvalidEnvelopeException;
use AuthService\Helper\Sharing\Envelope\IdempotencyGuard;
use AuthService\Helper\Sharing\Envelope\ShareEnvelope;
use AuthService\Helper\Sharing\Inbox\Events\InboundShareReceived;
use AuthService\Helper\Sharing\Inbox\InboundShareMessage;
use AuthService\Helper\Sharing\Intents\Exceptions\UnknownIntentException;
use AuthService\Helper\Sharing\Intents\IntentRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;

class InboundShareWebhookController
{
    public function __construct(
        protected IdempotencyGuard $idempotency,
        protected IntentRegistry $intents,
    ) {}

    public function receive(Request $request): JsonResponse
    {
        // 1. Parse envelope
        try {
            $raw = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($raw)) {
                throw new InvalidEnvelopeException('Envelope body must be a JSON object');
            }
            $env = ShareEnvelope::fromArray($raw);
        } catch (\JsonException | InvalidEnvelopeException $e) {
            return $this->error(400, 'invalid_envelope', $e->getMessage());
        }

        $sourceServiceId = (string) $request->attributes->get('source_service_id');
        $signatureHeader = (string) $request->header('X-Signature', '');

        // 2. Idempotency check — UNIQUE(source_service_id, idempotency_key)
        if ($this->idempotency->hasSeen($sourceServiceId, $env->idempotencyKey)) {
            return new JsonResponse([
                'message_id' => $env->messageId,
                'already_processed' => true,
            ], 200);
        }

        // 3. Persist with status=received
        try {
            $row = InboundShareMessage::create([
                'id' => (string) Str::uuid(),
                'envelope_version' => $env->envelopeVersion,
                'message_id' => $env->messageId,
                'correlation_id' => $env->correlationId,
                'intent' => $env->intent,
                'intent_version' => $env->intentVersion,
                'source_service_id' => $sourceServiceId,
                'target_service_id' => $env->targetServiceId,
                'user_id' => $env->userId,
                'idempotency_key' => $env->idempotencyKey,
                'issued_at' => $env->issuedAt,
                'payload' => $env->payload,
                'signature_header' => $signatureHeader,
                'headers' => $this->captureRelevantHeaders($request),
                'received_at' => now(),
                'processing_status' => 'received',
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Race: a parallel request inserted the row between our hasSeen() and now.
            // Treat as duplicate — same outcome as the fast path.
            return new JsonResponse([
                'message_id' => $env->messageId,
                'already_processed' => true,
            ], 200);
        }

        // 4. Validate against intent schema + dispatch typed event
        return $this->validateAndDispatch($row);
    }

    protected function validateAndDispatch(InboundShareMessage $row): JsonResponse
    {
        try {
            $schemaPath = $this->intents->schemaPathFor($row->intent);
        } catch (UnknownIntentException $e) {
            return $this->markRejectedSchema($row, "Unknown intent: {$row->intent}");
        }

        if ($schemaPath !== null) {
            $validator = new Validator();
            $payloadObject = json_decode(json_encode($row->payload)); // stdClass for the validator
            $validator->validate(
                $payloadObject,
                (object) ['$ref' => 'file://' . str_replace('\\', '/', $schemaPath)],
                Constraint::CHECK_MODE_NORMAL,
            );

            if (!$validator->isValid()) {
                $errors = collect($validator->getErrors())
                    ->map(fn ($e) => "{$e['property']}: {$e['message']}")
                    ->implode('; ');
                return $this->markRejectedSchema($row, $errors);
            }
        }

        $row->processing_status = 'validated';
        $row->save();

        // Hydrate typed payload + dispatch event
        $typedPayload = $this->intents->hydrate($row->intent, $row->payload);

        Event::dispatch(new InboundShareReceived(
            shareId: $row->correlation_id,
            intent: $row->intent,
            intentVersion: $row->intent_version,
            userId: $row->user_id,
            sourceServiceId: $row->source_service_id,
            payload: $typedPayload,
            messageId: $row->message_id,
            correlationId: $row->correlation_id,
            metadata: [
                'received_at' => $row->received_at?->toIso8601String(),
                'headers' => $row->headers ?? [],
            ],
        ));

        $row->processing_status = 'dispatched';
        $row->dispatched_at = now();
        $row->save();

        return new JsonResponse([
            'message_id' => $row->message_id,
            'status' => 'dispatched',
        ], 202);
    }

    protected function markRejectedSchema(InboundShareMessage $row, string $error): JsonResponse
    {
        $row->processing_status = 'rejected_schema';
        $row->processing_error = $error;
        $row->save();

        return new JsonResponse([
            'error' => 'schema_validation_failed',
            'message_id' => $row->message_id,
            'detail' => $error,
        ], 422);
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    protected function captureRelevantHeaders(Request $request): array
    {
        return collect($request->headers->all())
            ->only([
                'x-trust-key',
                'x-signature',
                'content-type',
                'user-agent',
            ])
            ->all();
    }

    protected function error(int $status, string $reason, string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => $reason,
            'message' => $message,
        ], $status);
    }
}
