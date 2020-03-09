<?php


namespace App\Controller;


use App\Entity\Article;
use App\Entity\ArticleReference;
use App\Service\UploaderHelper;
use Doctrine\ORM\EntityManagerInterface;
use http\Header;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ArticleReferenceAdminController extends BaseController
{
    // The id that will be passed in the URL will be the id of an article that we want to attach the reference to
    /**
     * @Route("/admin/article/{id}/references", name="admin_article_add_reference", methods={"POST"})
     * @IsGranted("MANAGE", subject="article")
     */
    public function uploadArticleReference(Article $article, Request $request, UploaderHelper $uploaderHelper, EntityManagerInterface $entityManager, ValidatorInterface $validator)
    {
        /** @var UploadedFile $uploadedFile */
        $uploadedFile = $request->files->get('reference');
        dump($uploadedFile);

        $violations = $validator->validate( // Using the validator service object and its validate() method and passing it the things I want to validate. Typically this would look into the object's class and read the validation annotations
                                            // But since this is a core class object I want to validate, I am also passing in the constraints that I want to validate against. The second argument passed to validate() can be an array of constraints
            $uploadedFile,
            [
                new NotBlank([
                    'message' => 'Please select a file to upload'
                ]),
                new File([
                    'maxSize' => '5M',
                    'mimeTypes' => [ // These are the mimeTypes that the file upload accepts, if a user tries to upload a different file type to one that's here then it will display an error
                        'image/*',
                        'application/pdf',
                        'application/msword',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                        'text/plain'
                    ],
                ])
                ]
        );

        if ($violations->count() > 0 ) { // If there are atleast one violations
          return $this->json($violations, 400); // Create a json resource which is being passed the $violations array and the status code 400
        }

        $filename = $uploaderHelper->uploadArticleReference($uploadedFile);

        $articleReference = new ArticleReference($article);
        $articleReference->setFilename($filename); // Storing the unique filename where this file was stored on the file system
        $articleReference->setOriginalFilename($uploadedFile->getClientOriginalName() ?? $filename); // if the client original name is missing for some reason, then fallback to $filename
        $articleReference->setMimeType($uploadedFile->getMimeType() ?? 'application/octet-stream'); // Just in case we want to know what type of file is being uploaded, we will store the files mime type, which is a property on a file object that says the type

        $entityManager->persist($articleReference);
        $entityManager->flush();

        return $this->json(
            $articleReference,
            201,
            [], // This is blank because we don't a custom response header
            [
                'groups' => ['main'] // Adding an array with groups set to main so that it only serializes the properties that are in that group
            ]

            );
    }

    /**
     * @Route("/admin/article/{id}/references", methods={"GET"}, name="admin_article_list_references")
     * @IsGranted("MANAGE", subject="article")
     */
    public function getArticleReferences(Article $article)
    {
        return $this->json(
            $article->getArticleReferences(),
            200,
            [],
            [
                'groups' => ['main']
            ]
            );
    }

    // The {id} that we will be passing in the URL below refers to the id of the ArticleReference object
    /**
     * @Route("/admin/article/references/{id}/download", name="admin_article_download_reference", methods={"GET"})
     */
    public function downloadArticleReference(ArticleReference $reference, UploaderHelper $uploaderHelper)
    {
        $article = $reference->getArticle(); // Getting the article object via the reference object method
        $this->denyAccessUnlessGranted('MANAGE', $article); // Doing the security check manually to check the users roles on this page

        // With a streamed response, when symfony is ready to send the data, it will execute our callback function which is being passed as the argument.
        $response = new StreamedResponse(function() use ($reference, $uploaderHelper) { // Add a use statement and passing the reference and uploader helper objects so that we can use them in the callback function
            $outputStream = fopen('php://output', 'wb'); // Anything we write to this stream will get echoed out to the user
            $fileStream = $uploaderHelper->readStream($reference->getFilePath(), false);

            stream_copy_to_stream($fileStream, $outputStream); // Copying $fileStream to $outputStream
        });
        $response->headers->set('Content-Type', $reference->getMimeType()); // Setting the HTTP response header to the mimetype of the reference object. For example, if it is a PDF document, the mimetype would be application/pdf
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT, // Telling Symfony if we want the user to open the file in a browser or just download it straight away
            $reference->getOriginalFilename() // When the user downloads the file, instead of giving it the unique name we give it when it gets uploaded, give it its original name.
        );
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    /**
     * @Route("/admin/article/references/{id}", name="admin_article_delete_reference", methods={"DELETE"})
     */
    public function deleteArticleReference(ArticleReference $reference, UploaderHelper $uploaderHelper, EntityManagerInterface $entityManager)
    {
        $article = $reference->getArticle();
        $this->denyAccessUnlessGranted('MANAGE', $article);

        $entityManager->remove($reference); // Deleting the reference file from the database
        $entityManager->flush();

        $uploaderHelper->deleteFile($reference->getFilePath(), false); // Deleting the reference file from the filesystem that it is in

        return new Response(null, 204);
    }

    /**
     * @Route("/admin/article/references/{id}", name="admin_article_update_reference", methods={"PUT"})
     */
    public function updateArticleReference(ArticleReference $reference, UploaderHelper $uploaderHelper, EntityManagerInterface $entityManager, SerializerInterface $serializer, Request $request, ValidatorInterface $validator)
    {
        $article = $reference->getArticle();
        $this->denyAccessUnlessGranted('MANAGE', $article);

        $serializer->deserialize( // Turning the JSON data that we get from the request into an existing ArticleReference object and making it update that reference
            $request->getContent(),
            ArticleReference::class,
            'json',
            [
                'object_to_populate' => $reference,
                'groups' => ['input'] // if any other fields are passed that are not in this group, they will just be ignored.
            ]
        );

        $violations = $validator->validate($reference);
        if ($violations->count() > 0 ) {
            return $this->json($violations, 400);
        }

        $entityManager->persist($reference);
        $entityManager->flush();

        return $this->json(
            $reference,
            200,
            [], // This is blank because we don't a custom response header
            [
                'groups' => ['main'] // Adding an array with groups set to main so that it only serializes the properties that are in that group
            ]

        );
    }
}