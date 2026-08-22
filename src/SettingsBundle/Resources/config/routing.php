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

use SolidInvoice\SettingsBundle\Action\CustomField\CreateAction as CustomFieldCreateAction;
use SolidInvoice\SettingsBundle\Action\CustomField\DeleteAction as CustomFieldDeleteAction;
use SolidInvoice\SettingsBundle\Action\CustomField\EditAction as CustomFieldEditAction;
use SolidInvoice\SettingsBundle\Action\CustomField\IndexAction as CustomFieldIndexAction;
use SolidInvoice\SettingsBundle\Action\CustomField\ReorderAction as CustomFieldReorderAction;
use SolidInvoice\SettingsBundle\Action\Index;
use SolidInvoice\SettingsBundle\Action\WhatsAppTest;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routingConfigurator): void {
    $routingConfigurator
        ->add('_settings', '/settings')
        ->controller(Index::class);

    // Kept under /settings on purpose: security.php restricts "^/settings" to
    // ROLE_ADMIN, so this inherits that rule instead of needing its own - and it
    // cannot be left out of a rule nobody remembered to add.
    $routingConfigurator
        ->add('_settings_whatsapp_test', '/settings/whatsapp/test')
        ->controller(WhatsAppTest::class)
        ->methods(['POST']);

    $routingConfigurator
        ->add('_settings_custom_fields', '/settings/custom-fields')
        ->controller(CustomFieldIndexAction::class)
        ->methods(['GET']);

    $routingConfigurator
        ->add('_settings_custom_fields_create', '/settings/custom-fields/new')
        ->controller(CustomFieldCreateAction::class)
        ->methods(['GET', 'POST']);

    $routingConfigurator
        ->add('_settings_custom_fields_edit', '/settings/custom-fields/{id}/edit')
        ->controller(CustomFieldEditAction::class)
        ->methods(['GET', 'POST']);

    $routingConfigurator
        ->add('_settings_custom_fields_delete', '/settings/custom-fields/{id}/delete')
        ->controller(CustomFieldDeleteAction::class)
        ->methods(['POST']);

    $routingConfigurator
        ->add('_settings_custom_fields_reorder', '/settings/custom-fields/reorder')
        ->controller(CustomFieldReorderAction::class)
        ->methods(['POST']);
};
