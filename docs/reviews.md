# Product reviews

Reviews are part of the engine rather than a satellite (parent plan §5.12). The
engine handles moderation, spam defense, verified-purchase badges, and a
denormalized rating on each product. Product listings read the rating from the
product row, so they never run a rating query per product.

## Data model

| Table | Holds |
|---|---|
| `product_reviews` | One review: `rating` (1–5), `title`, `body`, author name and email, the `customer_id` and `order_id` it came from, `is_verified_purchase`, and the moderation `status` (`pending`, `approved`, `rejected`, `spam`), plus `approved_at` and `reviewed_by_user_id`. |
| `product_review_media` | Media-library items attached to a review (`review_id`, `media_id`). `media_id` has no foreign key, so `media-library` stays optional. |

`products.avg_rating` and `products.reviews_count` hold the aggregate over
**approved** reviews only.

## Lifecycle

`ReviewService` owns every transition:

1. **Submit.** The `ap.ecommerce.review.submitting` filter runs first. It can
   rewrite the attributes, or return `null` to abort the submission. The
   review is then saved as `pending`.
2. **Automatic moderation.** The review passes through the
   `ap.ecommerce.review.moderating` filter. The bound `ReviewModerator` then
   returns `approve`, `reject`, `spam`, or `pending`, and the service applies
   that verdict. The default moderator, `NoopReviewModerator`, always returns
   `pending`, so every review waits for a person. To use your own moderator
   (Akismet, a classifier, "auto-approve verified purchases", …), bind it:

   ```php
   $this->app->singleton( ReviewModerator::class, AkismetReviewModerator::class );
   ```

3. **Submitted.** `ap.ecommerce.review.submitted` and `ReviewSubmitted` fire.
   By this point the review already has the status moderation gave it. If
   the review is still `pending`, staff get the
   `review.awaiting-moderation.admin` notification.
4. **Human moderation.** `approve()`, `reject()`, `markSpam()`, and
   `requeue()` each fire their hook: `ap.ecommerce.review.approved` (plus
   `ReviewApproved`), `.rejected`, or `.markedSpam`. `requeue()` fires no hook.

### Rating aggregate

`ProductRatingAggregator` listens on `approved`, `rejected`, and `markedSpam`.
The service also calls it when an approved review is requeued or deleted. It
**recomputes** the aggregate from the approved rows rather than adjusting it
by one, and it holds a lock on the product row while it does. Concurrent
moderation therefore can't make the aggregate drift. The product is saved
through Eloquent, so Scout re-indexes the new rating.

## Verified purchases

A review is a verified purchase when the reviewer is a signed-in customer and
cites an `order_id` that:

- belongs to that customer,
- is paid (`payment_status` is `paid` or `partially_refunded`), and
- contains the reviewed product.

If a cited order doesn't meet all three, the service drops it and the review
is stored as unverified. The review isn't rejected, so the response can't be
used to check whether someone else's order exists. Guests are never verified.

## Spam defense

- **Rate limit.** The `ecommerce.review.submit` policy allows 3 reviews per
  hour per customer (per IP for guests) and 10 per hour per IP.
- **Honeypot.** Add a hidden form field named
  `artisanpack.ecommerce.reviews.honeypot_field` (default `website`). Real
  shoppers leave it empty. If a submission fills it in, the API answers
  exactly as it would for a genuine review, but the review is filed straight
  to `spam`. It skips the moderator, the `submitted` hook and event, and the
  admin broadcast. Any non-empty value counts as filled in, including an
  array.
- **Guests.** `artisanpack.ecommerce.reviews.allow_guests` (default `true`)
  decides whether shoppers who aren't signed in can review. Guests must give
  a name and email.

## REST

| Method | Path | Access |
|---|---|---|
| GET | `products/{product}/reviews` | public; approved reviews of a storefront-visible product. Filters: `rating`, `is_verified_purchase`. Sorts: `rating`, `created_at`. |
| POST | `products/{product}/reviews` | signed-in shopper or guest, `ecommerce.review.submit`, Idempotency-Key |
| GET | `admin/reviews` | `ecommerce.review.viewAny`. Filters: `status`, `product_id`, `customer_id`, `rating`, `is_verified_purchase`. |
| GET | `admin/reviews/{review}` | `ecommerce.review.view` |
| POST | `admin/reviews/{review}/moderate` | `ecommerce.review.moderate`. Body: `{ action: approve\|reject\|spam\|pending, reason? }`. |
| DELETE | `admin/reviews/{review}` | `ecommerce.review.delete` |

On non-admin requests the `review` resource leaves out `status`,
`author_email`, `customer_id`, `order_id`, and `reviewed_by_user_id`.

With GraphQL subscriptions on, each submission is broadcast as
`reviewSubmitted` on `private-ecommerce.admin`.
