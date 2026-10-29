<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateAdmin extends Command
{
    protected $signature = 'mikausis:admin {--email= : Administratora e-pasts} {--name= : Administratora vārds} {--generate-password : Ģenerēt un vienreiz parādīt drošu paroli}';

    protected $description = 'Izveidot administratoru pirmajai iestādei bez publiskas noklusējuma paroles';

    public function handle(): int
    {
        $school = School::query()->orderBy('id')->first();
        if (! $school) {
            $this->error('Nav izveidota neviena iestāde. Vispirms palaidiet datubāzes sagatavošanu.');

            return self::FAILURE;
        }

        $email = trim((string) ($this->option('email') ?: $this->ask('E-pasts')));
        $name = trim((string) ($this->option('name') ?: $this->ask('Vārds')));
        $validator = Validator::make(compact('email', 'name'), [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $generated = (bool) $this->option('generate-password');
        if (! $generated && ! $this->input->isInteractive()) {
            $this->error('Interaktīvi ievadiet paroli vai norādiet --generate-password.');

            return self::FAILURE;
        }
        $password = $generated ? Str::password(24) : (string) $this->secret('Parole (vismaz 12 rakstzīmes)');
        if (mb_strlen($password) < 12) {
            $this->error('Parolei jābūt vismaz 12 rakstzīmēm.');

            return self::FAILURE;
        }
        if (! $generated && $password !== $this->secret('Atkārtojiet paroli')) {
            $this->error('Paroles nesakrīt.');

            return self::FAILURE;
        }

        $user = new User;
        $user->forceFill(['name' => $name, 'email' => $email, 'password' => $password, 'school_id' => $school->id, 'is_admin' => true])->save();
        $this->info('Administrators izveidots: '.$email.' · '.$school->name);
        if ($generated) {
            $this->line('Parole (saglabājiet tagad): '.$password);
        }

        return self::SUCCESS;
    }
}
