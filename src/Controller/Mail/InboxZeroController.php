<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Entity\User\User;
use App\Service\Mail\InboxZeroDays;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class InboxZeroController extends AbstractController
{
    use ChecksCsrf;

    /**
     * Posted by mail--inbox-zero when the inbox's own list shows nothing,
     * whether it opened that way or the last row was just archived.
     */
    #[Route('/mail/inbox-zero', name: 'app_mail_inbox_zero', methods: ['POST'])]
    public function record(Request $request, InboxZeroDays $days, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $this->assertCsrf($request, 'ajax');

        $days->record($user);
        $em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
