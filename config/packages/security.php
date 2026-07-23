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

use SolidInvoice\ApiBundle\Event\Listener\AuthenticationFailHandler;
use SolidInvoice\ApiBundle\Event\Listener\AuthenticationSuccessHandler;
use SolidInvoice\ApiBundle\Security\ApiTokenAuthenticator;
use SolidInvoice\ApiBundle\Security\Provider\ApiTokenUserProvider;
use SolidInvoice\McpBundle\Security\McpOAuthAuthenticator;
use SolidInvoice\McpBundle\Security\McpOAuthUserProvider;
use SolidInvoice\UserBundle\Security\OAuth\OAuthAuthenticator;
use SolidWorx\Platform\PlatformBundle\DependencyInjection\Extension\LoginExtension;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Config\SecurityConfig;

return static function (SecurityConfig $config): void {
    $config
        ->passwordHasher(PasswordAuthenticatedUserInterface::class)
        ->algorithm('auto');

    // Portal access levels (assigned per user under Users). Higher levels inherit
    // the access of the lower ones: Admin > Manager > Accountant > Staff > User.
    $config
        ->roleHierarchy('ROLE_ADMIN', ['ROLE_MANAGER'])
        ->roleHierarchy('ROLE_MANAGER', ['ROLE_STAFF', 'ROLE_ACCOUNTANT', 'ROLE_ORDERS'])
        ->roleHierarchy('ROLE_ACCOUNTANT', ['ROLE_STAFF'])
        ->roleHierarchy('ROLE_STAFF', ['ROLE_USER'])
        // Order team (e.g. a remote desk): the MobilesOnline orders portal only.
        // Managers and above inherit it; a user given ONLY this role can reach
        // /orders and nothing else in the invoicing app.
        ->roleHierarchy('ROLE_ORDERS', ['ROLE_USER'])
        ->roleHierarchy('ROLE_SUPER_ADMIN', ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH'])
        ->roleHierarchy('ROLE_CLIENT', ['ROLE_USER'])
        ->roleHierarchy('ROLE_USER', []);

    $config
        ->provider('api_token_user_provider')
        ->id(ApiTokenUserProvider::class);

    $config
        ->provider('mcp_oauth_user_provider')
        ->id(McpOAuthUserProvider::class);

    $config
        ->firewall('assets')
        ->pattern('^/(_(profiler|wdt)|css|images|js)/')
        ->security(false);

    $config
        ->firewall('api_doc')
        ->pattern('^/api/docs')
        ->lazy(true)
        ->security(false);

    $config
        ->firewall('installation')
        ->pattern('^/install')
        ->security(false);

    $config
        ->firewall('api_login')
        ->pattern('^/api/login')
        ->stateless(true)
        ->security(false)
        ->formLogin()
        ->provider('api_token_user_provider')
        ->checkPath('/api/login')
        ->successHandler(AuthenticationSuccessHandler::class)
        ->failureHandler(AuthenticationFailHandler::class);

    $config
        ->firewall('api')
        ->pattern('^/api')
        ->stateless(true)
        ->provider('api_token_user_provider')
        ->customAuthenticators([ApiTokenAuthenticator::class]);

    $config
        ->firewall('mcp_oauth_endpoints')
        ->pattern('^/oauth/(token|register|revoke)$')
        ->stateless(true)
        ->security(false);

    $config
        ->firewall('mcp_well_known')
        ->pattern('^/\.well-known/(oauth-authorization-server|oauth-protected-resource|mcp/server-card\.json|agent-skills/index\.json)')
        ->stateless(true)
        ->security(false);

    $config
        ->firewall('api_well_known')
        ->pattern('^/\.well-known/api-catalog$')
        ->stateless(true)
        ->security(false);

    $config
        ->firewall('mcp')
        ->pattern('^/_mcp')
        ->stateless(true)
        ->provider('mcp_oauth_user_provider')
        ->customAuthenticators([McpOAuthAuthenticator::class]);

    $mainFirewallConfig = LoginExtension::configureDefaultFormLogin($config, true);

    $mainFirewallConfig
        ->customAuthenticators([OAuthAuthenticator::class]);

    $mainFirewallConfig
        ->formLogin()
        ->defaultTargetPath('_select_company')
    ;

    $config->accessControl()
        ->path('^(?:' .
            '/_components/SystemInstallation|' .
            '/webhook/lemon_squeezy|' .
            '/view/(?:quote|invoice)/[A-Za-z0-9-]{36}(?:\.pdf)?|' .
            '/(?:login|register)$|' .
            '/forgot-password|' .
            '/oauth/connect|' .
            '/oauth/(token|register|revoke)$|' .
            '/\.well-known/oauth-authorization-server|' .
            '/\.well-known/oauth-protected-resource|' .
            '/\.well-known/mcp/server-card\.json$|' .
            '/\.well-known/agent-skills/index\.json$|' .
            '/\.well-known/api-catalog$|' .
            '/install|' .
            '/verify$|' .
            '/logout$|' .
            '/invite/accept/[a-zA-Z0-9-]{26}$|' .
            '/nmp-inventory$|' .
            '/imei-unlock$|' .
            '/store$|' .
            '/payments/create/[a-zA-Z0-9-]{36}$|' .
            '/payment/capture/(?:.*)|' .
            '/payments/done$' .
            ')')
        ->roles(['PUBLIC_ACCESS']);

    // Role-based access. Evaluated top-to-bottom, first match wins, so these must
    // stay ABOVE the catch-all "^/" => ROLE_USER rule below. Higher roles inherit
    // lower ones via the hierarchy, so e.g. an admin passes every rule here.
    // Admin-only configuration surfaces:
    $config->accessControl()->path('^/settings')->roles(['ROLE_ADMIN']);
    $config->accessControl()->path('^/users')->roles(['ROLE_ADMIN']);
    // Only tax-RATE management is admin; /tax/number/validate stays open (used by
    // client and invoice forms to validate VAT numbers).
    $config->accessControl()->path('^/tax/rates')->roles(['ROLE_ADMIN']);
    $config->accessControl()->path('^/notifications')->roles(['ROLE_ADMIN']);
    $config->accessControl()->path('^/billing')->roles(['ROLE_ADMIN']);
    $config->accessControl()->path('^/create-company')->roles(['ROLE_ADMIN']);
    $config->accessControl()->path('^/delete-company')->roles(['ROLE_ADMIN']);
    $config->accessControl()->path('^/payments/methods')->roles(['ROLE_ADMIN']);
    // Accountant surfaces (managers and admins inherit these):
    $config->accessControl()->path('^/payments')->roles(['ROLE_ACCOUNTANT']);
    $config->accessControl()->path('^/daily-ledger')->roles(['ROLE_ACCOUNTANT']);
    $config->accessControl()->path('^/sales')->roles(['ROLE_ACCOUNTANT']);
    $config->accessControl()->path('^/expenses')->roles(['ROLE_ACCOUNTANT']);
    // Manager surfaces:
    $config->accessControl()->path('^/clients')->roles(['ROLE_MANAGER']);
    $config->accessControl()->path('^/quotes')->roles(['ROLE_MANAGER']);
    $config->accessControl()->path('^/credit-notes')->roles(['ROLE_MANAGER']);
    // Invoices / purchases / stock: VIEWING is Staff, but creating, editing,
    // deleting and bulk stock import are Manager+ (staff is view-only). Payments
    // on purchases stay Accountant+. More specific write rules must come before
    // the general read rule for each resource.
    $config->accessControl()->path('^/invoices/(create|edit|clone|action|recurring/create|recurring/edit|recurring-action)')->roles(['ROLE_MANAGER']);
    $config->accessControl()->path('^/invoices')->roles(['ROLE_STAFF']);
    $config->accessControl()->path('^/purchases/new')->roles(['ROLE_MANAGER']);
    $config->accessControl()->path('^/purchases/[^/]+/(edit|delete)')->roles(['ROLE_MANAGER']);
    $config->accessControl()->path('^/purchases/[^/]+/pay')->roles(['ROLE_ACCOUNTANT']);
    $config->accessControl()->path('^/purchases')->roles(['ROLE_STAFF']);
    $config->accessControl()->path('^/stock/import')->roles(['ROLE_MANAGER']);
    $config->accessControl()->path('^/stock')->roles(['ROLE_STAFF']);
    // IMEI unlock-code admin (upload/manage) is Manager+. The public customer
    // lookup (/imei-unlock) is allowed anonymously via the PUBLIC_ACCESS list
    // above, which is matched first.
    $config->accessControl()->path('^/unlock-codes')->roles(['ROLE_MANAGER']);
    // Store admin (curating the public storefront) is Manager+. The public
    // storefront itself (/store) is allowed anonymously via the PUBLIC_ACCESS
    // list above, which is matched first.
    $config->accessControl()->path('^/store-admin')->roles(['ROLE_MANAGER']);
    // Orders portal: the dedicated order team plus everyone above them.
    $config->accessControl()->path('^/orders')->roles(['ROLE_ORDERS']);

    $config->accessControl()
        ->path('^/')
        ->roles(['ROLE_USER']);

    $config->accessControl()
        ->path('^/2fa')
        ->roles(['IS_AUTHENTICATED_2FA_IN_PROGRESS']);
};
