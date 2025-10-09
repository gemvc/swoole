<?php

namespace Gemvc\CLI\Commands;

use Gemvc\CLI\Commands\BaseCrudGenerator;
use Gemvc\CLI\Commands\CreateService;

class CreateCrud extends BaseCrudGenerator
{
    public function execute(): bool
    {
        if (empty($this->args[0])) {
            $this->error("Service name is required. Usage: gemvc create:crud ServiceName");
            return false;
        }

        try {
            // Create service with all components enabled
            $service = new CreateService($this->args, $this->options);
            $service->args = [$this->args[0], '-cmt']; // Use original input + all flags
            $service->execute();

            // @phpstan-ignore-next-line
            $serviceName = $this->formatServiceName($this->args[0]);
            $this->success("CRUD for {$serviceName} created successfully!");
            return true;
            
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return false;
        }
    }
} 