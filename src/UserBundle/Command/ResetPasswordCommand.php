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
use SolidInvoice\UserBundle\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Set (reset) a user's password from the command line, by e-mail address. Handy
 * when someone forgets their password and e-mail delivery isn't set up yet, e.g.
 *
 *     bin/console app:user:reset-password someone@example.com "NewPass123!"
 *
 * If the password argument is omitted, one is asked for interactively (hidden).
 */
#[AsCommand(
    name: 'app:user:reset-password',
    description: 'Set a new password for a user, looked up by e-mail address.',
)]
final class ResetPasswordCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The e-mail address of the account')
            ->addArgument('password', InputArgument::OPTIONAL, 'The new password (asked for if omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getArgument('email');

        $user = $this->userRepository->findOneBy(['email' => $email]);

        if (! $user instanceof User) {
            $io->error(sprintf('No user found with e-mail "%s".', $email));

            return Command::FAILURE;
        }

        $password = $input->getArgument('password');

        if (! is_string($password) || $password === '') {
            $password = (string) $io->askHidden('New password');
        }

        if ($password === '') {
            $io->error('Password cannot be empty.');

            return Command::FAILURE;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->eraseCredentials();
        $this->entityManager->flush();

        $io->success(sprintf('Password updated for %s. They can now log in with the new password.', $email));

        return Command::SUCCESS;
    }
}
