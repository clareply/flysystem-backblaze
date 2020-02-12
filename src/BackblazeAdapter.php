<?php

namespace Mhetreramesh\Flysystem;

use BackblazeB2\Client;
use DateTime;
use DateTimeInterface;
use Exception;
use GuzzleHttp\Psr7;
use InvalidArgumentException;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;

class BackblazeAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;

    protected $client;

    protected $bucketName;

    public function __construct(Client $client, $bucketName)
    {
        $this->client = $client;
        $this->bucketName = $bucketName;
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        return $this->getClient()->fileExists(['FileName' => $this->applyPathPrefix($path), 'BucketName' => $this->bucketName]);
    }

    /**
     * {@inheritdoc}
     */
    public function write($path, $contents, Config $config)
    {
        $file = $this->getClient()->upload([
            'BucketName' => $this->bucketName,
            'FileName'   => $this->applyPathPrefix($path),
            'Body'       => $contents,
        ]);

        return $this->getFileInfo($file);
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream($path, $resource, Config $config)
    {
        $file = $this->getClient()->upload([
            'BucketName' => $this->bucketName,
            'FileName'   => $this->applyPathPrefix($path),
            'Body'       => $resource,
        ]);

        return $this->getFileInfo($file);
    }

    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, Config $config)
    {
        $file = $this->getClient()->upload([
            'BucketName' => $this->bucketName,
            'FileName'   => $this->applyPathPrefix($path),
            'Body'       => $contents,
        ]);

        return $this->getFileInfo($file);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, Config $config)
    {
        $file = $this->getClient()->upload([
            'BucketName' => $this->bucketName,
            'FileName'   => $this->applyPathPrefix($path),
            'Body'       => $resource,
        ]);

        return $this->getFileInfo($file);
    }

    /**
     * {@inheritdoc}
     */
    public function read($path)
    {
        $file = $this->getClient()->getFile([
            'BucketName' => $this->bucketName,
            'FileName'   => $this->applyPathPrefix($path),
        ]);
        $fileContent = $this->getClient()->download([
            'FileId' => $file->getId(),
        ]);

        return ['contents' => $fileContent];
    }

    /**
     * {@inheritdoc}
     */
    public function readStream($path)
    {
        $stream = Psr7\stream_for();
        $download = $this->getClient()->download([
            'BucketName' => $this->bucketName,
            'FileName'   => $this->applyPathPrefix($path),
            'SaveAs'     => $stream,
        ]);
        $stream->seek(0);

        try {
            $resource = Psr7\StreamWrapper::getResource($stream);
        } catch (InvalidArgumentException $e) {
            return false;
        }

        return $download === true ? ['stream' => $resource] : false;
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newpath)
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function copy($path, $newPath)
    {
        return $this->getClient()->upload([
            'BucketName' => $this->bucketName,
            'FileName'   => $this->applyPathPrefix($newPath),
            'Body'       => @file_get_contents($path),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    {
        return $this->getClient()->deleteFile(['FileName' => $this->applyPathPrefix($path), 'BucketName' => $this->bucketName]);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($path)
    {
        return $this->getClient()->deleteFile(['FileName' => $this->applyPathPrefix($path), 'BucketName' => $this->bucketName]);
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($path, Config $config)
    {
        return $this->getClient()->upload([
            'BucketName' => $this->bucketName,
            'FileName'   => $this->applyPathPrefix($path),
            'Body'       => '',
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        $file = $this->getClient()->getFile(['FileName' => $this->applyPathPrefix($path), 'BucketName' => $this->bucketName]);

        return $this->getFileInfo($file);
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        $file = $this->getClient()->getFile(['FileName' => $this->applyPathPrefix($path), 'BucketName' => $this->bucketName]);

        return $this->getFileInfo($file);
    }

    /**
     * {@inheritdoc}
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false)
    {
        $fileObjects = $this->getClient()->listFiles([
            'BucketName' => $this->bucketName,
            'Prefix' => $this->getPathPrefix(),
        ]);
        if ($recursive === true && $directory === '') {
            $regex = '/^.*$/';
        } elseif ($recursive === true && $directory !== '') {
            $regex = '/^'.preg_quote($directory).'\/.*$/';
        } elseif ($recursive === false && $directory === '') {
            $regex = '/^(?!.*\\/).*$/';
        } elseif ($recursive === false && $directory !== '') {
            $regex = '/^'.preg_quote($directory).'\/(?!.*\\/).*$/';
        } else {
            throw new InvalidArgumentException();
        }
        $fileObjects = array_filter($fileObjects, function ($fileObject) use ($directory, $regex) {
            return 1 === preg_match($regex, $fileObject->getName());
        });
        $normalized = array_map(function ($fileObject) {
            return $this->getFileInfo($fileObject);
        }, $fileObjects);

        return array_values($normalized);
    }

    /**
     * Get a temporary download-url.
     *
     * @param string $path
     * @param DateTimeInterface $expiration
     * @param array $options
     * @return string
     * @throws Exception
     */
    public function getTemporaryUrl(string $path, DateTimeInterface $expiration, array $options = [])
    {
        $downloadUrl = $this->client->getDownloadUrl([
            'BucketName' => $this->bucketName,
            'FileName' => $path,
        ]);

        $now = new DateTime();

        $authorizationSettings = $this->client->getDownloadAuthorization([
            'BucketName' => $this->bucketName,
            'FileNamePrefix' => $this->getPathPrefix(),
            'ValidDurationInSeconds' => $now->diff($expiration)->s,
        ]);

        return sprintf('%s?Authorization=%s',
            $downloadUrl,
            $authorizationSettings['authorizationToken']
        );
    }

    /**
     * Get file info.
     *
     * @param $file
     *
     * @return array
     */
    protected function getFileInfo($file)
    {
        $normalized = [
            'type'      => 'file',
            'path'      => $file->getName(),
            'timestamp' => substr($file->getUploadTimestamp(), 0, -3),
            'size'      => $file->getSize(),
        ];

        return $normalized;
    }
}
