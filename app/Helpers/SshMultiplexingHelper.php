<?php

namespace App\Helpers;

use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;

class SshMultiplexingHelper
{
    public static function serverSshConfiguration(Server $server)
    {
        $privateKey = PrivateKey::findOrFail($server->private_key_id);
        $sshKeyLocation = $privateKey->getKeyLocation();
        $muxFilename = '/var/www/html/storage/app/ssh/mux/mux_'.$server->uuid;

        return [
            'sshKeyLocation' => $sshKeyLocation,
            'muxFilename' => $muxFilename,
        ];
    }

    public static function ensureMultiplexedConnection(Server $server)
    {
        if (! self::isMultiplexingEnabled()) {
            return;
        }

        $sshConfig = self::serverSshConfiguration($server);
        $muxSocket = $sshConfig['muxFilename'];
        $sshKeyLocation = $sshConfig['sshKeyLocation'];

        self::validateSshKey($sshKeyLocation);

        $checkCommand = "ssh -O check -o ControlPath=$muxSocket ";
        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $checkCommand .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }
        $checkCommand .= "{$server->user}@{$server->ip}";
        $process = Process::run($checkCommand);

        if ($process->exitCode() !== 0) {
            self::establishNewMultiplexedConnection($server);
        }
    }

    public static function establishNewMultiplexedConnection(Server $server)
    {
        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];
        $muxSocket = $sshConfig['muxFilename'];

        $connectionTimeout = config('constants.ssh.connection_timeout');
        $serverInterval = config('constants.ssh.server_interval');
        $muxPersistTime = config('constants.ssh.mux_persist_time');

        $establishCommand = "ssh -fNM -o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} ";

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $establishCommand .= ' -o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }

        $establishCommand .= self::getCommonSshOptions($server, $sshKeyLocation, $connectionTimeout, $serverInterval);
        $establishCommand .= "{$server->user}@{$server->ip}";

        $establishProcess = Process::run($establishCommand);

        if ($establishProcess->exitCode() !== 0) {
            throw new \RuntimeException('Failed to establish multiplexed connection: '.$establishProcess->errorOutput());
        }
    }

    public static function removeMuxFile(Server $server)
    {
        $sshConfig = self::serverSshConfiguration($server);
        $muxSocket = $sshConfig['muxFilename'];

        $closeCommand = "ssh -O exit -o ControlPath=$muxSocket ";
        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $closeCommand .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }
        $closeCommand .= "{$server->user}@{$server->ip}";
        Process::run($closeCommand);
    }

    public static function generateScpCommand(Server $server, string $source, string $dest)
    {
        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];
        $muxSocket = $sshConfig['muxFilename'];

        $timeout = config('constants.ssh.command_timeout');
        $muxPersistTime = config('constants.ssh.mux_persist_time');

        $scp_command = "timeout $timeout scp ";

        if (self::isMultiplexingEnabled()) {
            $scp_command .= "-o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} ";
            self::ensureMultiplexedConnection($server);
        }

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $scp_command .= '-o ProxyCommand="cloudflared access ssh --hostname %h" ';
        }

        $scp_command .= self::getCommonSshOptions($server, $sshKeyLocation, config('constants.ssh.connection_timeout'), config('constants.ssh.server_interval'), isScp: true);
        $scp_command .= "{$source} {$server->user}@{$server->ip}:{$dest}";

        return $scp_command;
    }

    public static function generateSshCommand(Server $server, string $command)
    {
        if ($server->settings->force_disabled) {
            throw new \RuntimeException('Server is disabled.');
        }

        $sshConfig = self::serverSshConfiguration($server);
        $sshKeyLocation = $sshConfig['sshKeyLocation'];
        $muxSocket = $sshConfig['muxFilename'];

        $timeout = config('constants.ssh.command_timeout');
        $muxPersistTime = config('constants.ssh.mux_persist_time');

        $ssh_command = "timeout $timeout ssh ";

        if (self::isMultiplexingEnabled()) {
            $ssh_command .= "-o ControlMaster=auto -o ControlPath=$muxSocket -o ControlPersist={$muxPersistTime} ";
            self::ensureMultiplexedConnection($server);
        }

        if (data_get($server, 'settings.is_cloudflare_tunnel')) {
            $ssh_command .= "-o ProxyCommand='cloudflared access ssh --hostname %h' ";
        }

        $ssh_command .= self::getCommonSshOptions($server, $sshKeyLocation, config('constants.ssh.connection_timeout'), config('constants.ssh.server_interval'));

        $command = "PATH=\$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/host/usr/local/sbin:/host/usr/local/bin:/host/usr/sbin:/host/usr/bin:/host/sbin:/host/bin && $command";
        $delimiter = Hash::make($command);
        $command = str_replace($delimiter, '', $command);

        $ssh_command .= "{$server->user}@{$server->ip} 'bash -se' << \\$delimiter".PHP_EOL
            .$command.PHP_EOL
            .$delimiter;

        return $ssh_command;
    }

    private static function isMultiplexingEnabled(): bool
    {
        return config('constants.ssh.mux_enabled') && ! config('coolify.is_windows_docker_desktop');
    }

    private static function validateSshKey(string $sshKeyLocation): void
    {
        $checkKeyCommand = "ls $sshKeyLocation 2>/dev/null";
        $keyCheckProcess = Process::run($checkKeyCommand);

        if ($keyCheckProcess->exitCode() !== 0) {
            throw new \RuntimeException("SSH key file not accessible: $sshKeyLocation");
        }
    }

    private static function getCommonSshOptions(Server $server, string $sshKeyLocation, int $connectionTimeout, int $serverInterval, bool $isScp = false): string
    {
        $options = "-i {$sshKeyLocation} "
            .'-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
            .'-o PasswordAuthentication=no '
            ."-o ConnectTimeout=$connectionTimeout "
            ."-o ServerAliveInterval=$serverInterval "
            .'-o RequestTTY=no '
            .'-o LogLevel=ERROR ';

        // Bruh
        if ($isScp) {
            $options .= "-P {$server->port} ";
        } else {
            $options .= "-p {$server->port} ";
        }

        return $options;
    }
}