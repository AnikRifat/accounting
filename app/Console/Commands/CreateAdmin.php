<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Interactively create the one super admin (no default password)';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run app:create-admin interactively; passwords must not be supplied on the command line.');

            return self::FAILURE;
        }
        if (User::where('role', Permissions::ROOT_ROLE)->exists()) {
            $this->error('A super admin already exists. There can be only one.');

            return self::FAILURE;
        }
        $email = strtolower(trim(text('Email', required: true)));
        $name = text('Name', required: true);
        $secret = password('Password (12+ characters, letters and numbers)', required: true);
        $validator = Validator::make(['email' => $email, 'name' => $name, 'password' => $secret], [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255', Password::min(12)->letters()->numbers()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user = new User($validator->validated());
        $user->forceFill(['role' => Permissions::ROOT_ROLE, 'is_active' => true])->save();
        $this->info('Super admin created. Sign in at /admin/login.');

        return self::SUCCESS;
    }
}
