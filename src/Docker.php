<?php

namespace Spatie\Docker;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Spatie\TemporaryDirectory\TemporaryDirectory;

class Docker
{
    private TemporaryDirectory $temporaryDir;

    public static function new(): Docker
    {
        return new self();
    }

    public function __construct()
    {
        $this->temporaryDir = (new TemporaryDirectory())->create();

        $this->startDaemon();
    }

    public function start(DockerContainer $config): DockerContainerInstance
    {
        $name = $config->name.'-'.substr(uniqid(), 0, 8);

        $process = Process::fromShellCommandline("docker run -p {$config->port}:22 --name {$name} -d --rm {$config->image}");
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $dockerContainer = new DockerContainerInstance(
            $config,
            $process->getOutput(),
            $name
        );

        return $this->writeAuthorizedKeysKeys($dockerContainer);
    }

    public function stop(DockerContainerInstance $dockerContainer)
    {
        $process = Process::fromShellCommandline("docker stop {$dockerContainer->getDockerIdentifier()}");
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    public function runInContainer(
        DockerContainerInstance $containerInstance,
        string $command
    ) {
        $process = Process::fromShellCommandline("docker exec -i {$containerInstance->getShortDockerIdentifier()} {$command}");
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    private function startDaemon()
    {
        // Starting docker deamon (omit for now)
    }

    private function writeAuthorizedKeysKeys(DockerContainerInstance $dockerContainer): DockerContainerInstance
    {
        $file = $this->temporaryDir->path().'/authorized_keys';

        file_put_contents(
            $file,
            array_map(fn(string $key) => $key.PHP_EOL, $dockerContainer->getConfig()->authorizedKeys)
        );

        $process = Process::fromShellCommandline("docker cp {$file} {$dockerContainer->getShortDockerIdentifier()}:/root/.ssh/authorized_keys");
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $this->runInContainer(
            $dockerContainer,
            'chmod 600 /root/.ssh/authorized_keys'
        );

        $this->runInContainer(
            $dockerContainer,
            'chown root:root /root/.ssh/authorized_keys'
        );

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $dockerContainer;
    }
}