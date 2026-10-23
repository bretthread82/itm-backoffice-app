<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /**
     * SharePoint. Tests replace it with Services::injectMock('sharepoint', ...).
     */
    public static function sharepoint(bool $getShared = true): \App\Services\SharePoint\SharePointClient
    {
        if ($getShared) {
            return static::getSharedInstance('sharepoint');
        }

        return new \App\Services\SharePoint\GraphClient();
    }

    /**
     * Microsoft sign-in round trips. Tests replace it with Services::injectMock('entra', ...).
     */
    public static function entra(bool $getShared = true): \App\Services\Entra\EntraClient
    {
        if ($getShared) {
            return static::getSharedInstance('entra');
        }

        return new \App\Services\Entra\MicrosoftClient();
    }

    /**
     * Where uploaded files live. filestore.driver in .env: "local" (default, writable/uploads on this server) or
     * "s3" (a bucket shared by all servers). Tests replace it with Services::injectMock('fileStore', ...).
     */
    public static function fileStore(bool $getShared = true): \App\Services\Files\FileStore
    {
        if ($getShared) {
            return static::getSharedInstance('fileStore');
        }

        if ((string) env('filestore.driver', 'local') !== 's3') {
            return new \App\Services\Files\LocalFileStore();
        }

        $bucket = (string) env('filestore.s3.bucket', '');
        if ($bucket === '') {
            throw new \RuntimeException('filestore.driver is s3 but filestore.s3.bucket is not set.');
        }
        $config = [
            'version'                 => 'latest',
            'region'                  => (string) env('filestore.s3.region', 'us-east-1'),
            'use_path_style_endpoint' => filter_var(env('filestore.s3.pathStyle', false), FILTER_VALIDATE_BOOLEAN),
        ];
        if ((string) env('filestore.s3.endpoint', '') !== '') {
            $config['endpoint'] = (string) env('filestore.s3.endpoint');
        }
        // Without a key the SDK falls back to the server's own role/credentials, which is preferred on AWS.
        if ((string) env('filestore.s3.key', '') !== '') {
            $config['credentials'] = ['key' => (string) env('filestore.s3.key'), 'secret' => (string) env('filestore.s3.secret', '')];
        }

        return new \App\Services\Files\S3FileStore(new \Aws\S3\S3Client($config), $bucket, (string) env('filestore.s3.prefix', ''));
    }

    /**
     * The system email transport. mail.transport in .env: "sendgrid" (default in production)
     * or "log" (default in development). Tests replace it with Services::injectMock('mailer', ...).
     */
    public static function mailer(bool $getShared = true): \App\Services\Mail\MailTransport
    {
        if ($getShared) {
            return static::getSharedInstance('mailer');
        }

        $mode = (string) env('mail.transport', ENVIRONMENT === 'production' ? 'sendgrid' : 'log');

        return $mode === 'sendgrid'
            ? new \App\Services\Mail\SendGridTransport((string) env('sendgrid.apiKey', ''))
            : new \App\Services\Mail\LogTransport();
    }
}
