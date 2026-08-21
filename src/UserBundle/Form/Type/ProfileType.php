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

namespace SolidInvoice\UserBundle\Form\Type;

use SolidInvoice\UserBundle\Entity\User;
use SolidInvoice\UserBundle\Validator\Constraints\UniqueWhatsAppNumber;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @see \SolidInvoice\UserBundle\Tests\Form\Type\ProfileTypeTest
 * @extends AbstractType<User>
 */
class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName')
            ->add('lastName')
            ->add('email', EmailType::class)
            // Checked here as well as on sign-up: a rule the sign-up form
            // enforces and the profile page does not is not a rule, it is a
            // speed bump - sign up with one number, change it afterwards.
            ->add('mobile', options: [
                'label' => 'WhatsApp Number',
                'constraints' => [
                    new UniqueWhatsAppNumber(),
                ],
            ])
            ->add('current_password', PasswordType::class, [
                'label' => 'Current Password',
                'mapped' => false,
                'constraints' => [
                    new NotBlank(),
                    new UserPassword(),
                ],
                'attr' => [
                    'autocomplete' => 'current-password',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'csrf_token_id' => 'profile',
        ]);
    }
}
