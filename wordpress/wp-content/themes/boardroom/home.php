<?php
// The posts page (/blog/): one page heading, then each post as a linked
// title with its date and excerpt. Full posts live on their own pages.
get_header();
$blog_id = (int) get_option('page_for_posts');
$blog_heading = $blog_id ? get_the_title($blog_id) : '';
if ($blog_heading === '' || strcasecmp($blog_heading, 'Blog') === 0) $blog_heading = 'Event Planning Tips & Guides';
?>
<div class="page-hero"><?php echo br_picture('PXL_20220912_160655168.jpg', 'The Boardroom event space'); ?><h1><?php echo esc_html($blog_heading); ?></h1></div>
<div class="wrap"><div class="content post-list">
<?php if (have_posts()): while (have_posts()): the_post(); ?>
<article class="post-card">
<h2 class="post-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
<p class="post-card-date"><time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date()); ?></time></p>
<div class="post-card-excerpt"><?php the_excerpt(); ?></div>
<p class="post-card-more"><a href="<?php the_permalink(); ?>">Read the guide<span class="screen-reader-text"> about <?php the_title(); ?></span></a></p>
</article>
<?php endwhile; ?>
<?php the_posts_pagination(['mid_size' => 1]); ?>
<?php else: ?>
<p>New planning guides are on the way. In the meantime, <a href="<?php echo esc_url(home_url('/#check-your-date')); ?>">check your date</a>.</p>
<?php endif; ?>
</div></div>
<?php get_footer();
