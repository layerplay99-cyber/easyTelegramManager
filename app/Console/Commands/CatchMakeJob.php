<?php

namespace App\Console\Commands;

use Illuminate\Foundation\Console\JobMakeCommand;
use Symfony\Component\Console\Input\InputArgument;

class CatchMakeJob extends JobMakeCommand
{
    protected $name = 'catch:make:job';
    protected $description = 'Create a new Job inside a CatchAdmin module';

    protected function getArguments()
    {
        return [
            ['name', InputArgument::REQUIRED, 'The name of the Job class'],
            ['module', InputArgument::REQUIRED, 'The module name (e.g. Telegram)'],
        ];
    }

    protected function getPath($name)
    {
        $module = ucfirst($this->argument('module'));
        $path = base_path("modules/{$module}/Jobs/");

        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        return $path . class_basename($name) . '.php';
    }

    protected function rootNamespace()
    {
        return 'Modules\\' . ucfirst($this->argument('module')) . '\\Jobs\\';
    }
}
