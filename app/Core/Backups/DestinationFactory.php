<?php

declare(strict_types=1);

namespace App\Core\Backups;

use App\Core\Backups\Destinations\Destination;
use App\Core\Backups\Destinations\GraphDestination;
use App\Core\Backups\Destinations\LocalDestination;
use App\Core\Backups\Destinations\S3Destination;
use App\Core\Backups\Destinations\WebDavDestination;
use App\Core\Backups\Http\CurlTransport;
use App\Core\Backups\Http\Transport;
use InvalidArgumentException;

/**
 * Knows the destination types: their settings form and how to build a working destination from saved settings.
 * Field kinds: text, password (secret, kept when left blank on edit), select, checkbox.
 */
class DestinationFactory
{
    public function __construct(private readonly ?Transport $http = null)
    {
    }

    /**
     * @return array<string, array{label: string, icon: string, summary: string, fields: array<string, array<string, mixed>>}>
     */
    public static function types(): array
    {
        return [
            'graph' => [
                'label'   => 'SharePoint / OneDrive',
                'icon'    => 'microsoft',
                'summary' => 'A document library or OneDrive through Microsoft Graph.',
                'fields'  => [
                    'tenant_id'     => ['label' => 'Directory (tenant) ID', 'kind' => 'text', 'required' => true],
                    'client_id'     => ['label' => 'Application (client) ID', 'kind' => 'text', 'required' => true],
                    'client_secret' => ['label' => 'Client secret', 'kind' => 'password', 'required' => true],
                    'target_kind'   => ['label' => 'Save to', 'kind' => 'select', 'required' => true, 'options' => ['site' => 'A SharePoint site', 'user' => 'A user\'s OneDrive', 'drive' => 'A drive by id'], 'default' => 'site'],
                    'target'        => ['label' => 'Site, user or drive', 'kind' => 'text', 'required' => true, 'help' => 'Site: contoso.sharepoint.com:/sites/Finance. User: their sign-in name. Drive: the drive id.'],
                    'folder'        => ['label' => 'Folder', 'kind' => 'text', 'required' => false, 'default' => 'ITM Backups', 'help' => 'Created if missing.'],
                ],
                'note' => 'Use a separate app registration with the Microsoft Graph application permission Files.ReadWrite.All (or Sites.Selected for one site) and grant admin consent.',
            ],
            's3' => [
                'label'   => 'S3-compatible bucket',
                'icon'    => 'database',
                'summary' => 'Amazon S3, MinIO, Wasabi, Backblaze B2, Cloudflare R2 and similar.',
                'fields'  => [
                    'endpoint'   => ['label' => 'Endpoint', 'kind' => 'text', 'required' => false, 'help' => 'Leave blank for Amazon S3. Otherwise the store\'s address, e.g. https://s3.eu-central-003.backblazeb2.com.'],
                    'region'     => ['label' => 'Region', 'kind' => 'text', 'required' => false, 'default' => 'us-east-1'],
                    'bucket'     => ['label' => 'Bucket', 'kind' => 'text', 'required' => true],
                    'prefix'     => ['label' => 'Folder in bucket', 'kind' => 'text', 'required' => false, 'default' => 'itm-backups'],
                    'access_key' => ['label' => 'Access key', 'kind' => 'text', 'required' => true],
                    'secret_key' => ['label' => 'Secret key', 'kind' => 'password', 'required' => true],
                    'path_style' => ['label' => 'Use path-style addresses', 'kind' => 'checkbox', 'required' => false, 'help' => 'Tick for MinIO and most self-hosted stores.'],
                ],
                'note' => 'Create a key that can only write to this bucket. For safety against ransomware, enable versioning or object lock on the bucket.',
            ],
            'webdav' => [
                'label'   => 'WebDAV',
                'icon'    => 'cloud',
                'summary' => 'Nextcloud, ownCloud, Synology and other WebDAV servers.',
                'fields'  => [
                    'url'      => ['label' => 'WebDAV address', 'kind' => 'text', 'required' => true, 'help' => 'e.g. https://cloud.example.com/remote.php/dav/files/backup-user'],
                    'username' => ['label' => 'User name', 'kind' => 'text', 'required' => true],
                    'password' => ['label' => 'Password', 'kind' => 'password', 'required' => true, 'help' => 'Use an app password where the server offers them.'],
                    'folder'   => ['label' => 'Folder', 'kind' => 'text', 'required' => false, 'default' => 'itm-backups'],
                ],
                'note' => '',
            ],
            'local' => [
                'label'   => 'Folder on a disk or share',
                'icon'    => 'hdd',
                'summary' => 'A folder on this server or a mounted network share. Not off-site unless the share is.',
                'fields'  => [
                    'path' => ['label' => 'Folder path', 'kind' => 'text', 'required' => true, 'help' => 'The web/cron user must be able to write here.'],
                ],
                'note' => '',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function make(string $type, array $settings): Destination
    {
        $http = $this->http ?? new CurlTransport();

        return match ($type) {
            'local'  => new LocalDestination($settings),
            's3'     => new S3Destination($settings, $http),
            'webdav' => new WebDavDestination($settings, $http),
            'graph'  => new GraphDestination($settings, $http),
            default  => throw new InvalidArgumentException('Unknown destination type.'),
        };
    }
}
