<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Actions\ApiTokens\CreateApiToken;
use App\Actions\ApiTokens\RevokeApiToken;
use App\Enums\ApiTokenAbility;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Personal access tokens, managed under My Account -- spec §11: "Tokens are
 * managed under My Account: create (name, abilities, optional expiry), list
 * with last-used timestamps, revoke. The plaintext token is shown exactly
 * once, on creation."
 *
 * item/api-sanctum-tokens (issue #22). Every query and mutation here is
 * scoped through Auth::user()->tokens(), never a bare
 * App\Models\PersonalAccessToken::query() -- the same ownership-is-the-check
 * shape App\Livewire\Settings\Security already uses for a user's own
 * passkeys, and there is no separate Policy for the same reason that page
 * has none: a signed-in user managing their OWN tokens is not a question
 * Spatie permissions or directory_access have any business answering.
 *
 * The checkbox list restricts the FORM to App\Enums\ApiTokenAbility's eight
 * values, which is a courtesy to whoever is filling it in, not the guard:
 * App\Models\PersonalAccessToken::booted() is what actually refuses an
 * unknown ability, and it holds even if this restriction here were ever
 * loosened by accident. See that model's own docblock.
 */
#[Title('API tokens')]
class ApiTokens extends Component
{
    public string $name = '';

    /**
     * @var list<string>
     */
    public array $selectedAbilities = [];

    /**
     * The chosen expiry date, or null for a token that never expires.
     * Native date input elements always send an empty string rather than
     * null for "no value", so createToken() folds '' to null before
     * validating -- 'nullable' only short-circuits the other rules for a
     * PHP null, never for an empty string, and 'date' rejects ''.
     */
    public ?string $expiresAt = null;

    /**
     * The newly minted plaintext token, held ONLY in server-side component
     * state for the request that just created it -- never written to the
     * database (only its SHA-256 hash is, by
     * Laravel\Sanctum\HasApiTokens::createToken()) and never re-populated by
     * mount(), so a fresh render of this page -- a reload, a new tab, a
     * later visit -- starts with this null and has no way to recover the
     * value. That is the whole mechanism behind spec §11's "shown exactly
     * once, on creation".
     */
    #[Locked]
    public ?string $plainTextToken = null;

    #[Locked]
    public ?int $plainTextTokenId = null;

    public bool $showRevokeModal = false;

    #[Locked]
    public ?int $revokingTokenId = null;

    #[Locked]
    public string $revokingTokenName = '';

    /**
     * Creates a token for the signed-in user with the chosen name, abilities
     * and optional expiry.
     */
    public function createToken(): void
    {
        // See $expiresAt's own docblock: '' means "no value" from the
        // native date input, and only a real null lets 'nullable' skip the
        // 'date'/'after' rules below.
        $this->expiresAt = $this->expiresAt !== '' ? $this->expiresAt : null;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'selectedAbilities' => ['required', 'array', 'min:1'],
            'selectedAbilities.*' => ['string', Rule::in(ApiTokenAbility::values())],
            'expiresAt' => ['nullable', 'date', 'after:today'],
        ]);

        // Narrowed to the known set server-side too, the same
        // array_intersect() shape App\Livewire\Admin\Roles::saveRole() uses
        // for its own checkbox list -- the validation rule above already
        // refuses anything else, but this is what is actually PASSED to
        // createToken(), not merely what the request claimed.
        $abilities = array_values(array_intersect($validated['selectedAbilities'], ApiTokenAbility::values()));

        /** @var User $user */
        $user = Auth::user();

        $newToken = app(CreateApiToken::class)->handle(
            $user,
            $validated['name'],
            $abilities,
            $validated['expiresAt'] !== null ? Carbon::parse($validated['expiresAt'])->endOfDay() : null,
        );

        $this->plainTextToken = $newToken->plainTextToken;
        $this->plainTextTokenId = (int) $newToken->accessToken->getKey();

        $this->reset('name', 'selectedAbilities', 'expiresAt');

        Flux::toast(variant: 'success', text: __('Token created. Copy it now -- it will not be shown again.'));
    }

    /**
     * Shows the revoke confirmation modal for one of the signed-in user's
     * own tokens.
     */
    public function confirmRevoke(int $tokenId): void
    {
        // find() + abort_if, not findOrFail() -- the same reasoning
        // App\Livewire\Admin\Users::changeRole() gives for the identical
        // choice: findOrFail() raises ModelNotFoundException, which a real
        // request renders as a 404 but which a Livewire component test does
        // not see as one at all.
        $token = $this->ownTokensQuery()->find($tokenId);

        abort_if($token === null, 404);

        $this->revokingTokenId = (int) $token->getKey();
        $this->revokingTokenName = (string) $token->name;
        $this->showRevokeModal = true;
    }

    /**
     * Revokes the token the confirmation modal is currently open for.
     */
    public function revokeToken(): void
    {
        if ($this->revokingTokenId === null) {
            return;
        }

        // Re-resolved through the OWN-tokens query rather than trusted from
        // the locked property it was set from: this is the actual boundary
        // between "my token" and "anyone's token id", scoped the same way
        // confirmRevoke() scopes it, not merely re-asserted from state a
        // client cannot forge anyway (Locked) but that costs nothing extra
        // to re-check here.
        $token = $this->ownTokensQuery()->find($this->revokingTokenId);

        if ($token !== null) {
            app(RevokeApiToken::class)->handle($token);

            if ($this->plainTextTokenId === (int) $token->getKey()) {
                $this->reset('plainTextToken', 'plainTextTokenId');
            }
        }

        $this->closeRevokeModal();
    }

    public function closeRevokeModal(): void
    {
        $this->showRevokeModal = false;
        $this->revokingTokenId = null;
        $this->revokingTokenName = '';
    }

    public function render(): View
    {
        $tokens = $this->ownTokensQuery()
            ->latest()
            ->get()
            ->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'created_at_diff' => $token->created_at?->diffForHumans(),
                'last_used_at_diff' => $token->last_used_at?->diffForHumans(),
                'expires_at_diff' => $token->expires_at?->diffForHumans(),
            ])
            ->all();

        return view('livewire.settings.api-tokens', [
            'tokens' => $tokens,
            'availableAbilities' => ApiTokenAbility::cases(),
        ]);
    }

    /**
     * Typed with SANCTUM's PersonalAccessToken, not doccum's subclass, and
     * the difference is a real one rather than a formality.
     * HasApiTokens::tokens() is declared as MorphMany<Laravel\Sanctum\
     * PersonalAccessToken, ...>; every row this returns is really an
     * App\Models\PersonalAccessToken, but only because
     * Sanctum::usePersonalAccessTokenModel() swapped the model at runtime in
     * DoccumServiceProvider, which static analysis cannot see and should not
     * be asked to take on trust. Narrowing the annotation to the subclass
     * would be asserting something this file has not established -- PHPStan
     * said so, and it was right.
     *
     * Nothing here needs the subclass anyway: it adds no public API, only a
     * saving hook. The ability guard lives on the model precisely so callers
     * like this one do not have to know about it.
     *
     * @return MorphMany<PersonalAccessToken, User>
     */
    private function ownTokensQuery(): MorphMany
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->tokens();
    }
}
