<?php

namespace Modules\BranchManagers\Http\Requests\Concerns;

/**
 * The mobile «Welcome to Assab» verify/login screens label the account field
 * «Registered» and, depending on the app build, POST its value as
 * `email`/`phone`/`username`/`login` instead of `identifier` — which tripped a
 * hard "The identifier field is required" (422) on a screen whose field was
 * visibly filled. Fold any of those aliases onto `identifier` before the rules
 * run. A request that already carries `identifier` is left untouched, so the
 * existing contract and every current client keep working.
 */
trait NormalizesIdentifier
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('identifier')) {
            return;
        }

        foreach (['email', 'phone', 'username', 'login', 'registered', 'registered_email'] as $alias) {
            if ($this->filled($alias)) {
                $this->merge(['identifier' => $this->input($alias)]);

                return;
            }
        }
    }
}
