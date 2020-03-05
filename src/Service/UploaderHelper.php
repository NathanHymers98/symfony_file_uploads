<?php


namespace App\Service;



use Gedmo\Sluggable\Util\Urlizer;
use Symfony\Component\Asset\Context\RequestStackContext;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class UploaderHelper // This class will handle all things related to uploading files
{
    const ARTICLE_IMAGE = 'article_image';

    private $uploadsPath;

    private $requestStackContext;

    public function __construct(string $uploadsPath, RequestStackContext $requestStackContext)
    {
        $this->uploadsPath = $uploadsPath;
        $this->requestStackContext = $requestStackContext;
    }

    public function uploadArticleImage(UploadedFile $uploadedFile): string // This method gets passed an uploaded image, gives it a destination to be saved, normalizes the file name and then returns the newFilename variable which is a string
    {
        $destination = $this->uploadsPath.'/'.self::ARTICLE_IMAGE; // Setting the destination of the image file to this folder

        $originalFilename = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME); // The will give us the original file name, but without the .jpeg or .png extensions

        $newFilename = Urlizer::urlize($originalFilename) . '-' . uniqid() . '.' . $uploadedFile->guessExtension(); // Uses the original filename, then adds a unique identifier, then adds the exact extension of the file.
                                                                                                                    // guessExtension() looks at the file contents, determines the type of file from the contents and returns the correct file extension for the contents
                                                                                                                    // The Urlizer takes the original file name, and makes it normalized. e.g. if it has spaces, the spaces will be replaced with dashes

        $uploadedFile->move(
            $destination, // Moving the file that was uploaded to the $destination we defined above
            $newFilename // Using the $newFileName variable so that all uploaded files have a unique name
        );

        return $newFilename;
    }

    // This method will take an argument like 'article_image/image.jpg' and use it as $path and it will return a string that will be the actual public path to the file
    public function getPublicPath(string $path): string
    {
        return $this->requestStackContext
            ->getBasePath().'/uploads/'.$path; // if our app lives at the route of the domain, like it does now, getBaseUrl() will just return an empty string
                                                // but if it lives in a sub-directory, like 'the_spacebar' it will return '/the_spacebar' so the images on the show page will still display.

    }
}