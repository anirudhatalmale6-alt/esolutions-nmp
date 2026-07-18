<?php

declare(strict_types=1);

/*
 * This file is part of SolidInvoice project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace SolidInvoice\UserBundle\Action;

use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Enum\PortalRole;
use SolidInvoice\UserBundle\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

/**
 * Lets an admin set the portal access level (role) for another user. Only
 * reachable by admins (both here and via the /users access-control rule). A user
 * cannot change their own role, so the last admin can't accidentally lock
 * themselves out.
 */
#[IsGranted('ROLE_ADMIN')]
final class EditUser extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly Security $security,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (! Ulid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        $user = $this->userRepository->find(Ulid::fromString($id));

        if (! $user instanceof User) {
            throw $this->createNotFoundException();
        }

        $currentUser = $this->security->getUser();
        $isSelf = $currentUser instanceof User && (string) $currentUser->getId() === (string) $user->getId();

        if ($request->isMethod('POST')) {
            return $this->save($request, $user, $isSelf);
        }

        return $this->renderForm($user, $isSelf);
    }

    private function save(Request $request, User $user, bool $isSelf): Response
    {
        if (! $this->isCsrfTokenValid('user.role', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Your session expired, please try again.');

            return $this->redirect($request->getUri());
        }

        $role = PortalRole::tryFrom((string) $request->request->get('role'));

        if ($role === null) {
            $this->addFlash('error', 'Please choose a valid role.');

            return $this->renderForm($user, $isSelf);
        }

        // Guard against self-lockout: you can raise your own access (e.g. to Super
        // User) but never drop yourself below admin.
        if ($isSelf && ! $role->isAdministrative()) {
            $this->addFlash('error', 'You cannot lower your own access below Admin. Ask another admin to change it for you.');

            return $this->renderForm($user, $isSelf);
        }

        // A single portal role per user; setRoles() clears any previous one and
        // drops the implicit ROLE_USER.
        $user->setRoles([$role->value]);

        $this->userRepository->save($user);

        $this->addFlash('success', sprintf('%s is now a %s.', $user->getUserIdentifier(), $role->label()));

        return $this->redirectToRoute('_users_list');
    }

    private function renderForm(User $user, bool $isSelf): Response
    {
        return $this->render('@SolidInvoiceUser/Users/edit_role.html.twig', [
            'user' => $user,
            'currentRole' => PortalRole::fromRoles($user->getRoles()),
            'roles' => PortalRole::cases(),
            'isSelf' => $isSelf,
        ]);
    }
}
