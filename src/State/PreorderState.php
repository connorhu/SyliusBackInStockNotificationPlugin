<?php

declare(strict_types=1);

namespace Webgriffe\SyliusBackInStockNotificationPlugin\State;

/**
 * What the product page shows to the current visitor for a pre-order target.
 */
enum PreorderState: string
{
    /** Not sellable to the visitor and pre-orders are not accepted. */
    case NotPreorderable = 'not_preorderable';

    /** Not sellable to the visitor, pre-orderable, and the visitor has no active pre-order. */
    case CanPreorder = 'can_preorder';

    /** The visitor has an active (waiting or notified) pre-order without a hold for them. */
    case Preordered = 'preordered';

    /** A unit is held for the visitor's own pre-order. */
    case HeldForYou = 'held_for_you';

    /** Units exist, but every one is held for somebody else and the visitor may not buy them. */
    case HeldForOthers = 'held_for_others';

    /** The visitor can buy now (hold rules applied). */
    case Sellable = 'sellable';
}
