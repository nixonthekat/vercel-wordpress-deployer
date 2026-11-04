<?php
/**
 * Content triggers.
 *
 * @package VercelWordPressDeployer
 */

namespace VercelWordPressDeployer;

defined( 'ABSPATH' ) || exit;

/**
 * Marks a deployment when an enabled content type changes publicly.
 */
class Triggers {

	/**
	 * Registers post hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_after_insert_post', array( self::class, 'on_after_insert_post' ), 10, 4 );
		add_action( 'before_delete_post', array( self::class, 'on_before_delete_post' ), 10, 2 );
	}

	/**
	 * Queues a deployment after a post and its meta have been saved.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post      $post Saved post.
	 * @param bool         $update Whether this was an update.
	 * @param WP_Post|null $post_before Previous post.
	 * @return void
	 */
	public static function on_after_insert_post( $post_id, $post, $update, $post_before ) {
		if ( ! $post instanceof \WP_Post || (int) $post_id !== (int) $post->ID ) {
			return;
		}

		$before = $post_before instanceof \WP_Post ? $post_before : null;

		if ( ! self::should_deploy( $post, $before, (bool) $update ) ) {
			return;
		}

		Deployer::mark();
	}

	/**
	 * Queues a deployment when a live post is deleted without being trashed first.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post being deleted.
	 * @return void
	 */
	public static function on_before_delete_post( $post_id, $post ) {
		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( (int) $post_id );
		}

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! self::should_deploy( $post, null, true ) ) {
			return;
		}

		Deployer::mark();
	}

	/**
	 * Whether this post change should rebuild the site.
	 *
	 * @param WP_Post      $post Post after the change.
	 * @param WP_Post|null $before Post before the change.
	 * @param bool         $update Whether this was an update.
	 * @return bool
	 */
	private static function should_deploy( $post, $before, $update ) {
		$enabled = Settings::get();
		$types   = $enabled['post_types'];

		if ( ! in_array( $post->post_type, $types, true ) ) {
			return false;
		}

		if ( wp_installing() || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return false;
		}

		if ( wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) ) {
			return false;
		}

		if ( is_user_logged_in() && ! current_user_can( 'edit_post', $post->ID ) ) {
			$can_delete = 'before_delete_post' === current_filter() && current_user_can( 'delete_post', $post->ID );

			if ( ! $can_delete && ( ! defined( 'WP_CLI' ) || ! WP_CLI ) ) {
				return false;
			}
		}

		$should = self::is_public_change( $post, $before );

		/**
		 * Filters whether a content change triggers a deployment.
		 *
		 * @param bool         $should Whether to deploy.
		 * @param \WP_Post     $post Post after the change.
		 * @param \WP_Post|null $before Post before the change.
		 * @param bool         $update Whether this was an update.
		 */
		return (bool) apply_filters( 'vercel_wordpress_deployer_should_deploy', $should, $post, $before, $update );
	}

	/**
	 * Published, private, and scheduled content affects the site. So does leaving those states.
	 *
	 * @param WP_Post      $post Post after the change.
	 * @param WP_Post|null $before Post before the change.
	 * @return bool
	 */
	private static function is_public_change( $post, $before ) {
		if ( 'auto-draft' === $post->post_status || 'inherit' === $post->post_status ) {
			return false;
		}

		$live = array( 'publish', 'private', 'future' );

		if ( in_array( $post->post_status, $live, true ) ) {
			return true;
		}

		if ( $before instanceof \WP_Post && in_array( $before->post_status, $live, true ) ) {
			return true;
		}

		return false;
	}
}
