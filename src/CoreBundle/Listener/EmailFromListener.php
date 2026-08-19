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

namespace SolidInvoice\CoreBundle\Listener;

use SolidInvoice\SettingsBundle\SystemConfig;
use SolidInvoice\UserBundle\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * @see \SolidInvoice\CoreBundle\Tests\Listener\EmailFromListenerTest
 */
final readonly class EmailFromListener implements EventSubscriberInterface
{
    public function __construct(
        private SystemConfig $config,
        private TokenStorageInterface $tokenStorage
    ) {
    }

    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();

        if (! $message instanceof Email) {
            return;
        }

        // Platform-wide for the same reason as the transport: an email sent while
        // no company is selected (somebody registering) would otherwise pick an
        // arbitrary business's From address, and one sent inside a member's
        // company would find their untouched default. Their own value still wins
        // if they have set one. See SettingsRepository::platformValue().
        $fromAddress = (string) $this->config->getPlatformWide('email/from_address');

        if ('' !== $fromAddress) {
            $fromName = (string) $this->config->getPlatformWide('email/from_name');
            $from = new Address($fromAddress, $fromName);
            $message->from($from);
            $event->getEnvelope()->setSender($from);
            $message->getHeaders()->remove('Sender');
        } else {
            $token = $this->tokenStorage->getToken();

            if ($token instanceof TokenInterface) {
                /** @var User $user */
                $user = $token->getUser();
                $from = Address::create($user->getEmail());
                $message->from($from);
                $event->getEnvelope()->setSender($from);
                $message->getHeaders()->remove('Sender');
            }
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MessageEvent::class => ['__invoke', -256],
        ];
    }
}
