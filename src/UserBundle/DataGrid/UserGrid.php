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

namespace SolidInvoice\UserBundle\DataGrid;

use Override;
use SolidInvoice\DataGridBundle\Attributes\AsDataGrid;
use SolidInvoice\DataGridBundle\Grid;
use SolidInvoice\DataGridBundle\GridBuilder\Action\Action;
use SolidInvoice\DataGridBundle\GridBuilder\Column\Column;
use SolidInvoice\DataGridBundle\GridBuilder\Column\RelativeDateColumn;
use SolidInvoice\DataGridBundle\GridBuilder\Column\StatusColumn;
use SolidInvoice\DataGridBundle\GridBuilder\Column\StringColumn;
use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Enum\PortalRole;

#[AsDataGrid(name: 'users_list', title: 'Users')]
final class UserGrid extends Grid
{
    /**
     * Shared by both confirmation columns so they cannot drift apart and mean
     * different things by the same colour.
     *
     * @var array<string, string>
     */
    private const array VERIFICATION_COLOURS = [
        'confirmed' => 'success',
        'not confirmed' => 'warning',
        'unknown' => 'info',
        'no number' => 'secondary',
    ];

    public function entityFQCN(): string
    {
        return User::class;
    }

    /**
     * @return Column[]
     */
    #[Override]
    public function columns(): array
    {
        return [
            StringColumn::new('email')
                ->label('Email Address'),
            StatusColumn::new('roles')
                ->label('Account type')
                ->sortable(false)
                ->searchable(false)
                ->formatValue(static fn (mixed $value, User $user): string => PortalRole::fromRoles($user->getRoles())?->label() ?? 'No role')
                // Super User stands out in red so it is obvious which account runs
                // the whole portal.
                ->statusMap([
                    'super user' => 'danger',
                    'admin' => 'primary',
                    'manager' => 'info',
                    'accountant' => 'success',
                    'staff' => 'secondary',
                    'order team' => 'warning',
                    'no role' => 'secondary',
                ]),
            StringColumn::new('mobile')
                ->label('Mobile')
                ->formatValue(fn ($value) => $value ?: '—'),
            /*
             * What has actually been PROVED about this person, split by channel.
             *
             * The account-wide `verified` flag cannot answer this: it is set by
             * whichever confirmation link was opened, and the link goes out on
             * both email and WhatsApp. Somebody who tapped the WhatsApp one has
             * proved their number answers and nothing at all about their inbox.
             *
             * "Unknown" is for accounts confirmed before the channel was
             * recorded - a real third state, not a polite way of saying no.
             */
            StatusColumn::new('emailVerifiedAt')
                ->label('Email confirmed')
                ->searchable(false)
                ->formatValue(static fn (mixed $value, User $user): string => match (true) {
                    $user->isEmailVerified() => 'confirmed',
                    $user->isVerifiedWithoutChannel() => 'unknown',
                    default => 'not confirmed',
                })
                ->statusMap(self::VERIFICATION_COLOURS),
            StatusColumn::new('mobileVerifiedAt')
                ->label('Number confirmed')
                ->searchable(false)
                ->formatValue(static fn (mixed $value, User $user): string => match (true) {
                    $user->isMobileVerified() => 'confirmed',
                    $user->isVerifiedWithoutChannel() => 'unknown',
                    ($user->getMobile() ?? '') === '' => 'no number',
                    default => 'not confirmed',
                })
                ->statusMap(self::VERIFICATION_COLOURS),
            RelativeDateColumn::new('created')
                ->label('Joined'),
            RelativeDateColumn::new('lastLogin')
                ->label('Last Login')
                ->formatValue(fn ($value) => $value ?: 'Never'),
            StatusColumn::new('enabled')
                ->label('Status')
                ->formatValue(fn ($value) => $value ? 'active' : 'disabled')
                ->statusMap([
                    'active' => 'success',
                    'disabled' => 'danger',
                ]),
        ];
    }

    /**
     * @return Action[]
     */
    #[Override]
    public function actions(): array
    {
        return [
            Action::new('_user_edit', ['id' => 'id'])
                ->label('Set Role')
                ->icon('user-cog'),
        ];
    }
}
