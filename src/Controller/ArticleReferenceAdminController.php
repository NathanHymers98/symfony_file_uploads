<?php


namespace App\Controller;


use App\Entity\Article;
use App\Entity\ArticleReference;
use App\Service\UploaderHelper;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ArticleReferenceAdminController extends BaseController
{
    // The id that will be passed in the URL will be the id of an article that we want to attach the reference to
    /**
     * @Route("/admin/article/{id}/references", name="admin_article_add_reference", methods={"POST"})
     * @IsGranted("MANAGE", subject="article")
     */
    public function uploadArticleReference(Article $article, Request $request, UploaderHelper $uploaderHelper, EntityManagerInterface $entityManager)
    {
        /** @var UploadedFile $uploadedFile */
        $uploadedFile = $request->files->get('reference');

        $filename = $uploaderHelper->uploadArticleReference($uploadedFile);

        $articleReference = new ArticleReference($article);
        $articleReference->setFilename($filename); // Storing the unique filename where this file was stored on the file system
        $articleReference->setOriginalFilename($uploadedFile->getClientOriginalName() ?? $filename); // if the client original name is missing for some reason, then fallback to $filename
        $articleReference->setMimeType($uploadedFile->getMimeType() ?? 'application/octet-stream'); // Just in case we want to know what type of file is being uploaded, we will store the files mime type, which is a property on a file object that says the type

        $entityManager->persist($articleReference);
        $entityManager->flush();

        return $this->redirectToRoute('admin_article_edit', [
            'id' => $article->getId()
        ]);
    }
}