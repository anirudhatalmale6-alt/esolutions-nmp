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

namespace SolidInvoice\CoreBundle\Action\Marketplace;

use SolidInvoice\CoreBundle\Company\CompanySelector;
use SolidInvoice\CoreBundle\Entity\CommunityPost;
use SolidInvoice\CoreBundle\Entity\Company;
use SolidInvoice\CoreBundle\Entity\MarketplaceAd;
use SolidInvoice\CoreBundle\Marketplace\MarketplaceManager;
use SolidInvoice\CoreBundle\Membership\MembershipManager;
use SolidInvoice\CoreBundle\Repository\CommunityCommentRepository;
use SolidInvoice\CoreBundle\Repository\CommunityPostRepository;
use SolidInvoice\CoreBundle\Repository\MarketplaceAdRepository;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use function array_map;
use function array_merge;
use function trim;

/**
 * The Marketplace home page.
 *
 * Three things on one page, in the order a buyer wants them:
 *
 *  1. the search - who has the model I am after, straight from their stock;
 *  2. four paid classified adverts, sold by the platform owner;
 *  3. the community feed, where any member posts stock, prices or a warning,
 *     and anybody else answers underneath.
 *
 * Anyone may read all three. What signing in changes is that the seller's name
 * and WhatsApp button appear, and that the boxes for writing a post or a reply
 * appear - the feed is a market between members, not a public comment section.
 */
final class Search
{
    /** One screenful a buyer scrolls, not an archive. */
    private const int FEED_LIMIT = 30;

    public function __construct(
        private readonly MarketplaceManager $marketplace,
        private readonly Security $security,
        private readonly MarketplaceAdRepository $ads,
        private readonly CommunityPostRepository $posts,
        private readonly CommunityCommentRepository $comments,
        private readonly MembershipManager $membership,
        private readonly CompanySelector $companySelector,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[Template('@SolidInvoiceCore/Marketplace/search.html.twig')]
    public function __invoke(Request $request): array
    {
        $query = trim((string) $request->query->get('q', ''));
        $loggedIn = $this->security->isGranted('IS_AUTHENTICATED_REMEMBERED');

        // One listing per vendor, each with the list of models they hold.
        $rows = $query !== '' ? $this->marketplace->searchGrouped($query) : [];

        // Guests see the same listings but with the seller's identity and number
        // stripped out server-side, so nothing private ever reaches the browser.
        if (! $loggedIn) {
            $rows = array_map(
                static fn (array $r): array => array_merge($r, ['business' => '', 'whatsapp' => '', 'chatUrl' => '', 'logo' => '']),
                $rows
            );
        }

        return [
            'query' => $query,
            'searched' => $query !== '',
            'loggedIn' => $loggedIn,
            'results' => $rows,
            'ads' => $this->liveAds(),
            'feed' => $this->feed(),
            'canPost' => $loggedIn && $this->companySelector->getCompany() !== null,
        ];
    }

    /**
     * The adverts to draw, checked against the advertiser one more time here.
     *
     * A slot is sold by the month, so the row saying "in place 2" outlives the
     * arrangement that paid for it. Asking the membership at render time means an
     * advert stops the moment the owner switches the button off or the membership
     * lapses, instead of on the day somebody remembers to clear the slot.
     *
     * @return list<MarketplaceAd>
     */
    private function liveAds(): array
    {
        $live = [];

        foreach ($this->ads->findLive() as $ad) {
            $company = $ad->getCompany();

            // No company on it means the platform's own advert - the owner
            // advertising the portal, or filling an empty place.
            if (! $company instanceof Company || $this->membership->hasClassifiedsAccess($company)) {
                $live[] = $ad;
            }
        }

        return $live;
    }

    /**
     * The feed, with each post's replies attached in one query rather than one
     * query per post.
     *
     * @return list<array{post: CommunityPost, comments: list<\SolidInvoice\CoreBundle\Entity\CommunityComment>}>
     */
    private function feed(): array
    {
        $posts = $this->posts->findFeed(self::FEED_LIMIT);

        if ($posts === []) {
            return [];
        }

        $comments = $this->comments->findForPosts($posts);
        $isOwner = $this->security->isGranted('ROLE_SUPER_ADMIN');
        $mine = $this->companySelector->getCompany();

        $feed = [];

        foreach ($posts as $post) {
            $id = $post->getId();

            $feed[] = [
                'post' => $post,
                'comments' => $id === null ? [] : ($comments[$id->toString()] ?? []),
                // Worked out here rather than in the template: the two companies
                // come from different queries, so they are never the same object
                // and only their ids can be compared.
                'canRemove' => $isOwner || ($mine !== null && $post->getCompany()?->getId()?->equals($mine) === true),
            ];
        }

        return $feed;
    }
}
