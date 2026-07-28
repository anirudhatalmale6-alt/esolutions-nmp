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

use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use function implode;

/**
 * List every account: e-mail, portal role(s) and the companies they belong to
 * (with each company's membership plan and whether it's verified). Handy for the
 * platform owner to see exactly which e-mail to reset a password for, or to pick
 * a real account to log in as when testing plan restrictions.
 */
#[AsCommand(
    name: 'app:user:list',
    description: 'List all users with their e-mail, role and companies (plan + verified).',
)]
final class ListUsersCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rows = [];

        foreach ($this->userRepository->findAll() as $user) {
            if (! $user instanceof User) {
                continue;
            }

            $companies = [];

            foreach ($user->getCompanies() as $company) {
                if (! $company instanceof Company) {
                    continue;
                }

                $companies[] = sprintf(
                    '%s [%s%s]',
                    $company->getName(),
                    $company->getMembershipPlan(),
                    $company->isVerified() ? ', verified' : ', NOT verified'
                );
            }

            $rows[] = [
                $user->getEmail(),
                implode(', ', $user->getRoles()),
                $companies === [] ? '(none)' : implode("\n", $companies),
            ];
        }

        if ($rows === []) {
            $io->warning('No users found.');

            return Command::SUCCESS;
        }

        $io->table(['E-mail', 'Roles', 'Companies (plan + status)'], $rows);
        $io->note(sprintf('%d user(s). Reset a password with: app:user:reset-password <email> "<new-password>"', count($rows)));

        return Command::SUCCESS;
    }
}
