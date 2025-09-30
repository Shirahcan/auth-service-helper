<account-switcher
    id="{{ $id }}"
    auth-url="{{ $authUrl }}"
    api-key="{{ $apiKey }}"
    @if($roles) roles="{{ $roles }}" @endif>
</account-switcher>
