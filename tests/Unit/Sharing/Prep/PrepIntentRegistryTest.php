<?php

namespace Tests\Unit\Sharing\Prep;

use AuthService\Helper\Sharing\Prep\Intents\PrepIntentHandler;
use AuthService\Helper\Sharing\Prep\Intents\PrepIntentRegistry;
use AuthService\Helper\Sharing\Prep\PrepResource;
use AuthService\Helper\Sharing\Prep\PromoteResult;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;

class PrepIntentRegistryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\AuthService\Helper\AuthServiceHelperServiceProvider::class];
    }

    public function test_register_and_get_handler(): void
    {
        $reg = new PrepIntentRegistry();
        $h = new PrepIntentRegistryTestHandler();
        $reg->register('test', $h);

        $this->assertTrue($reg->has('test'));
        $this->assertSame($h, $reg->get('test'));
    }

    public function test_get_unknown_intent_throws(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        (new PrepIntentRegistry())->get('missing');
    }

    public function test_agreement_sign_builtin_is_registered_via_service_provider(): void
    {
        /** @var PrepIntentRegistry $reg */
        $reg = $this->app->make(PrepIntentRegistry::class);
        $this->assertTrue($reg->has('agreement_sign'));
    }
}

class PrepIntentRegistryTestHandler implements PrepIntentHandler
{
    public static function intentSlug(): string { return 'test'; }
    public static function intentVersion(): string { return '1.0'; }
    public function render(PrepResource $temp): Response { return new Response(''); }
    public function submit(PrepResource $temp, array $signedData): void {}
    public function promote(PrepResource $temp, array $share): PromoteResult
    {
        return new PromoteResult('perm', 'active');
    }
}
