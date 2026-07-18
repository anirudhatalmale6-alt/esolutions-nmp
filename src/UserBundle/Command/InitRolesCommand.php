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

namespace SolidInvoice\UserBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Enum\PortalRole;
use SolidInvoice\UserBundle\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-time (and safely re-runnable) backfill: gives every existing user the Admin
 * role if they don't already have a portal role. Run once right after deploying
 * roles so nobody who was using the system loses access, then downgrade specific
 * staff from the Users page.
 */
#[AsCommand(
    name: 'app:users:init-roles',
    description: 'Grant Admin to existing users that have no portal role yet (run once after enabling roles).',
)]
final class InitRolesCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $granted = 0;
        $skipped = 0;

        foreach ($this->userRepository->findAll() as $user) {
            if (! $user instanceof User) {
                continue;
            }

            if (PortalRole::fromRoles($user->getRoles()) !== null) {
                ++$skipped;
                continue;
            }

            $user->addRole(PortalRole::Admin->value);
            ++$granted;
        }

        if ($granted > 0) {
            $this->entityManager->flush();
        }

        $io->success(sprintf('Granted Admin to %d user(s); %d already had a role.', $granted, $skipped));

        return Command::SUCCESS;
    }
}
