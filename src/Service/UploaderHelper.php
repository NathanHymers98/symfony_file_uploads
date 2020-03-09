<?php


namespace App\Service;



use Gedmo\Sluggable\Util\Urlizer;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\FilesystemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Asset\Context\RequestStackContext;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class UploaderHelper // This class will handle all things related to uploading files
{
    const ARTICLE_IMAGE = 'article_image';
    const ARTICLE_REFERENCE = 'article_reference';

    private $filesystem;

    private $privateFilesystem;

    private $requestStackContext;

    private $logger;

    private $uploadedAssetsBaseUrl;

    public function __construct(FilesystemInterface $publicUploadFilesystem, FilesystemInterface $privateUploadFilesystem, RequestStackContext $requestStackContext, LoggerInterface $logger, string $uploadedAssetsBaseUrl)
    {
        $this->filesystem = $publicUploadFilesystem;
        $this->privateFilesystem = $privateUploadFilesystem;
        $this->requestStackContext = $requestStackContext;
        $this->logger = $logger;
        $this->uploadedAssetsBaseUrl = $uploadedAssetsBaseUrl;
    }

    // This method gets passed an uploaded image, gives it a destination to be saved, normalizes the file name and then returns the newFilename variable which is a string
    public function uploadArticleImage(File $file, ?string $existingFilename): string // The second argument '?string etc.' is a nullable string because sometimes there might not be an existing file to delete so it does not need to be passed to this method for this method to work
    {

        $newFilename = $this->uploadFile($file, self::ARTICLE_IMAGE, true); // The last argument 'true' means that it will use the public file system defined in the uploadFile method

        if($existingFilename) { // If the method is passed this argument and it is set to something, then delete it
            try {
                $result = $this->filesystem->delete(self::ARTICLE_IMAGE . '/' . $existingFilename); // We have to put the full path name as the argument for the delete method so it knows which one to delete and where

                if ($result === false) {
                    throw new \Exception(sprintf('Could not delete old uploaded file "%s"', $existingFilename));
                }

            } catch (FileNotFoundException $e) { // Using a try catch to catch the error and display a logger alert message with the filename that caused the issue. Messages like this would be setup to automatically be sent to Slack
                                                // Thanks to the try catch the user will see no error, and we will be able to go to the logs and see what the error is
                $this->logger->alert(sprintf('Old uploaded file "%s" was missing when trying to delete', $existingFilename));
            }
        }

        return $newFilename;
    }

    public function uploadArticleReference(File $file): string // This reference file is being saved to a private directory, which is why the arguments in uploadFile are different compared to when we are uploading an image which we want to be public
    {
        return $this->uploadFile($file, self::ARTICLE_REFERENCE, false); // The last argument 'false' means that it will use the private file system defined in the uploadFile method
    }


    // This method will take an argument like 'article_image/image.jpg' and use it as $path and it will return a string that will be the actual public path to the file
    public function getPublicPath(string $path): string
    {
        return $this->requestStackContext
            ->getBasePath().$this->uploadedAssetsBaseUrl.'/'.$path; // if our app lives at the route of the domain, like it does now, getBaseUrl() will just return an empty string
                                                // but if it lives in a sub-directory, like 'the_spacebar' it will return '/the_spacebar' so the images on the show page will still display.

    }

    /**
     * @return resource
     */
    public function readStream(string $path, bool $isPublic) // Passing the isPublic argument so that we know which filesystem to read from
    {
        $filesystem = $isPublic ? $this->filesystem : $this->privateFilesystem; // Getting the correct file system, if $isPbulic is set to true, then it will use the public system and if not it will use the private system

        $resource =  $filesystem->readStream($path);

        if ($resource === false) { // If nothing is passed to this method, flysystem will return false. So we need to check if the $resource object is exactly equal to false, and if it is show a more helpful exception message
            throw new \Exception(sprintf('Error opening stream for "%s"', $path));
        }

        return $resource;
    }

    public function deleteFile(string $path, bool $isPublic)
    {
        $filesystem = $isPublic ? $this->filesystem : $this->privateFilesystem;

        $result = $filesystem->delete($path);

        if ($result === false) {
            throw new \Exception(sprintf('Error deleting "%s"', $path));
        }
    }

    private function uploadFile(File $file, string $directory, bool $isPublic): string
    {
        if ($file instanceof UploadedFile) { // Both methods will get the original filename. Which one it uses depends on what kind of object it is
            $originalFilename = $file->getClientOriginalName();
        } else {
            $originalFilename = $file->getFilename();
        }

        $newFilename = Urlizer::urlize(pathinfo($originalFilename, PATHINFO_FILENAME)) . '-' . uniqid() . '.' . $file->guessExtension(); // Uses the original filename, then adds a unique identifier, then adds the exact extension of the file.
                                                                                                                                                // guessExtension() looks at the file contents, determines the type of file from the contents and returns the correct file extension for the contents
                                                                                                                                                // The Urlizer takes the original file name, and makes it normalized. e.g. if it has spaces, the spaces will be replaced with dashes

        $filesystem = $isPublic ? $this->filesystem : $this->privateFilesystem; // if the value set to $filesystem is public, then use filesystem property. (which is for public files) Otherwise, use the privateFilesystem property

        $stream = fopen($file->getPathname(), 'r'); // since we just need to read the file, I am using the 'r' flag
        $result = $filesystem->writeStream( // This is used to create new files
            $directory.'/'.$newFilename, // Passing it the filename. $directory is the directory inside the file system where the file will be stored
            $stream // Gives us the absolute file path in the system
        );

        if ($result === false) { // If something goes wrong whilst writing the stream of files, and it is not already covered automatically by a different exception, then throw this one
            throw new \Exception(sprintf('Could not write uploaded file "%s"', $newFilename));
        }

        if (is_resource($stream)) { // This is needed because some flysystem adapters close the stream by themselves, so we are just making sure that ours is closing
            fclose($stream);
        }

        return $newFilename;
    }
}