<?php

namespace Distributor\ExternalConnections;

use \Distributor\ExternalConnection as ExternalConnection;
use \Distributor\Utils;

class WordPressExternalConnection extends ExternalConnection {

	static $slug               = 'wp';
	static $label              = 'WordPress REST API';
	static $auth_handler_class = '\Distributor\Authentications\WordPressBasicAuth';
	static $namespace          = 'wp/v2';

	static $timeout = 5;

	/**
	 * This is a utility function for parsing annoying API link headers returned by the types endpoint
	 *
	 * @param  array $type
	 * @since  0.8
	 * @return string|bool
	 */
	private function parse_type_items_link( $type ) {
		try {
			if ( isset( $type['_links']['wp:items'][0]['href'] ) ) {
				$link = $type['_links']['wp:items'][0]['href'];
				return $link;
			}
		} catch ( \Exception $e ) {
			// Bummer
		}

		try {
			if ( isset( $type['_links']['https://api.w.org/items'][0]['href'] ) ) {
				$link = $type['_links']['https://api.w.org/items'][0]['href'];
				return $link;
			}
		} catch ( \Exception $e ) {
			// Even bigger bummer
		}

		return false;
	}

	/**
	 * Remotely get posts
	 *
	 * @param  array $args
	 * @since  0.8
	 * @return array|\WP_Post|\WP_Error
	 */
	public function remote_get( $args = array() ) {
		$id = ( empty( $args['id'] ) ) ? false : $args['id'];

		$query_args = array();

		$post_type = ( empty( $args['post_type'] ) ) ? 'post' : $args['post_type'];

		if ( empty( $id ) ) {
			$query_args['post_status'] = ( empty( $args['post_status'] ) ) ? 'any' : $args['post_status'];
			$posts_per_page            = ( empty( $args['posts_per_page'] ) ) ? get_option( 'posts_per_page' ) : $args['posts_per_page'];
			$query_args['page']        = ( empty( $args['paged'] ) ) ? 1 : $args['paged'];

			if ( isset( $args['post__in'] ) ) {
				if ( empty( $args['post__in'] ) ) {
					// If post__in is empty, we can just stop right here
					/**
					 * Filter the remote_get request
					 *
					 * @since 1.0
					 *
					 * @param array  $args {
					 * 		@type array Items to get.
					 * 		@type int Total number of items to get.
					 * }
					 * @param  array  $args The arguments originally passed to .remote_get'.
					 * @param  object $this The authentication class.
					 */
					return apply_filters(
						'dt_remote_get', [
							'items'       => array(),
							'total_items' => 0,
						], $args, $this
					);
				}

				$query_args['include'] = $args['post__in'];
			} elseif ( isset( $args['post__not_in'] ) ) {
				$query_args['exclude'] = $args['post__not_in'];
			}

			if ( ! empty( $args['s'] ) ) {
				$query_args['search'] = $args['s'];
			}
		}

		static $types_urls;
		$types_urls = array();

		if ( empty( $types_urls[ $post_type ] ) ) {
			/**
			 * First let's get the actual route if not cached. We don't know the "plural" of our post type
			 */

			/**
			 * Todo: This should be cached in a transient
			 */

			$path = self::$namespace;

			$types_path = untrailingslashit( $this->base_url ) . '/' . $path . '/types';

			if ( function_exists( 'vip_safe_wp_remote_get' ) && \Distributor\Utils\is_vip_com() ) {
				$types_response = vip_safe_wp_remote_get(
					$types_path,
					false, 3, 3, 10, $this->auth_handler->format_get_args()
				);
			} else {
				$types_response = wp_remote_get(
					$types_path, $this->auth_handler->format_get_args( array( 'timeout' => self::$timeout ) )
				);
			}

			if ( is_wp_error( $types_response ) ) {
				return $types_response;
			}

			if ( 404 === wp_remote_retrieve_response_code( $types_response ) ) {
				return new \WP_Error( 'bad-endpoint', esc_html__( 'Could not connect to API endpoint.', 'distributor' ) );
			}

			$types_body = wp_remote_retrieve_body( $types_response );

			if ( empty( $types_body ) ) {
				return new \WP_Error( 'no-response-body', esc_html__( 'Response body is empty', 'distributor' ) );
			}

			$types_body_array = json_decode( $types_body, true );

			if ( empty( $types_body_array ) || empty( $types_body_array[ $post_type ] ) ) {
				return new \WP_Error( 'no-pull-post-type', esc_html__( 'Could not determine remote post type endpoint', 'distributor' ) );
			}

			$types_urls[ $post_type ] = $this->parse_type_items_link( $types_body_array[ $post_type ] );

			if ( empty( $types_urls[ $post_type ] ) ) {
				return new \WP_Error( 'no-pull-post-type', esc_html__( 'Could not determine remote post type endpoint', 'distributor' ) );
			}
		}

		$args_str = '';

		if ( ! empty( $posts_per_page ) ) {
			$args_str .= 'per_page=' . (int) $posts_per_page;
		}

		/**
		 * Filter the remote_get query arguments
		 *
		 * @since 1.0
		 *
		 * @param  array  $query_args The existing query arguments.
		 * @param  array  $args       The arguments originally passed to .remote_get'.
		 * @param  object $this       The authentication class.
		 */
		$query_args = apply_filters( 'dt_remote_get_query_args', $query_args, $args, $this );

		foreach ( $query_args as $arg_key => $arg_value ) {
			if ( is_array( $arg_value ) ) {
				foreach ( $arg_value as $arg_value_value ) {
					if ( ! empty( $args_str ) ) {
						$args_str .= '&';
					}

					$args_str .= $arg_key . '[]=' . $arg_value_value;
				}
			} else {
				if ( ! empty( $args_str ) ) {
					$args_str .= '&';
				}

				$args_str .= $arg_key . '=' . $arg_value;
			}
		}

		$context = 'view';

		$prelim_get_args = $this->auth_handler->format_get_args();

		/**
		 * See if we are trying to authenticate
		 */
		if ( ! empty( $prelim_get_args ) && ! empty( $prelim_get_args['headers'] ) && ! empty( $prelim_get_args['headers']['Authorization'] ) ) {
			$context = 'edit';

			if ( ! empty( $args_str ) ) {
				$args_str .= '&';
			}

			$args_str .= 'context=edit';
		}

		if ( ! empty( $id ) ) {
			$posts_url = untrailingslashit( $types_urls[ $post_type ] ) . '/' . $id . '/?context=' . $context;
		} else {
			$posts_url = untrailingslashit( $types_urls[ $post_type ] ) . '/?' . $args_str;
		}

		if ( function_exists( 'vip_safe_wp_remote_get' ) && \Distributor\Utils\is_vip_com() ) {
			$posts_response = vip_safe_wp_remote_get(
				/**
				 * Filter the URL that remote_get will use
				 *
				 * @since 1.0
				 *
				 * @param  string $posts_url  The posts URL
				 * @param  string $args       The arguments originally passed to .remote_get'.
				 * @param  object $this       The authentication class.
				 */
				apply_filters( 'dt_remote_get_url', $posts_url, $args, $this ),
				false, 3, 3, 10, $this->auth_handler->format_get_args()
			);
		} else {
			$posts_response = wp_remote_get( apply_filters( 'dt_remote_get_url', $posts_url, $args, $this ), $this->auth_handler->format_get_args( array( 'timeout' => 45 ) ) );
		}

		if ( is_wp_error( $posts_response ) ) {
			return $posts_response;
		}

		$response_code = wp_remote_retrieve_response_code( $posts_response );

		if ( 200 !== $response_code ) {

			if ( 404 === $response_code ) {
				return new \WP_Error( 'bad-endpoint', esc_html__( 'Could not connect to API endpoint.', 'distributor' ) );
			}

			$posts_body = json_decode( wp_remote_retrieve_body( $posts_response ), true );

			$code    = empty( $posts_body['code'] ) ? 'endpoint-error' : esc_html( $posts_body['code'] );
			$message = empty( $posts_body['message'] ) ? esc_html__( 'API endpoint error.', 'distributor' ) : esc_html( $posts_body['message'] );

			return new \WP_Error( $code, $message );
		}

		$posts_body = wp_remote_retrieve_body( $posts_response );

		if ( empty( $posts_body ) ) {
			return new \WP_Error( 'no-response-body', esc_html__( 'Response body is empty', 'distributor' ) );
		}

		$posts           = json_decode( $posts_body, true );
		$formatted_posts = array();

		$response_headers = wp_remote_retrieve_headers( $posts_response );

		if ( empty( $id ) ) {
			foreach ( $posts as $post ) {
				$post['full_connection'] = ( ! empty( $response_headers['X-Distributor'] ) );

				$formatted_posts[] = $this->to_wp_post( $post );
			}

			$total_posts = wp_remote_retrieve_header( $posts_response, 'X-WP-Total' );
			if ( empty( $total_posts ) ) {
				$total_posts = count( $formatted_posts );
			}

			return apply_filters(
				'dt_remote_get', [
					'items'       => $formatted_posts,
					'total_items' => $total_posts,
				], $args, $this
			);
		} else {
			return apply_filters( 'dt_remote_get', $this->to_wp_post( $posts ), $args, $this );
		}
	}

	/**
	 * Pull items. Pass array of posts, each post should look like:
	 * [ 'remote_post_id' => POST ID TO GET, 'post_id' (optional) => POST ID TO MAP TO ]
	 *
	 * @param  array $items
	 * @since  0.8
	 * @return array
	 */
	public function pull( $items ) {
		$created_posts = array();

		foreach ( $items as $item_array ) {
			$post = $this->remote_get( [ 'id' => $item_array['remote_post_id'] ] );

			if ( is_wp_error( $post ) ) {
				$created_posts[] = $post;
				continue;
			}

			$post_props = get_object_vars( $post );
			$post_array = array();

			foreach ( $post_props as $key => $value ) {
				$post_array[ $key ] = $value;
			}

			if ( ! empty( $item_array['post_id'] ) ) {
				$post_array['ID'] = $item_array['post_id'];
			} else {
				unset( $post_array['ID'] );
			}

			// Remove date stuff
			unset( $post_array['post_date'] );
			unset( $post_array['post_date_gmt'] );
			unset( $post_array['post_modified'] );
			unset( $post_array['post_modified_gmt'] );

			/**
			 * Filter the arguments passed into wp_insert_post during a pull.
			 *
			 * @since 1.0
			 *
			 * @param  array              $post_array                     The post to be inserted.
			 * @param  array              $item_array['remote_post_id']   The remote post ID.
			 * @param  object             $post                           The request that got the post.
			 * @param  ExternalConnection $this                           The distributor connection pulling the post.
			 */
			$new_post = wp_insert_post( apply_filters( 'dt_pull_post_args', $post_array, $item_array['remote_post_id'], $post, $this ) );

			update_post_meta( $new_post, 'dt_original_post_id', (int) $item_array['remote_post_id'] );
			update_post_meta( $new_post, 'dt_original_source_id', (int) $this->id );
			update_post_meta( $new_post, 'dt_syndicate_time', time() );
			update_post_meta( $new_post, 'dt_original_post_url', esc_url_raw( $post_array['link'] ) );
			update_post_meta( $new_post, 'dt_original_site_name', sanitize_text_field( $post_array['original_site_name'] ) );
			update_post_meta( $new_post, 'dt_original_site_url', sanitize_text_field( $post_array['original_site_url'] ) );

			if ( empty( $post_array['full_connection'] ) ) {
				update_post_meta( $new_post, 'dt_full_connection', false );
			} else {
				update_post_meta( $new_post, 'dt_full_connection', true );
			}

			if ( ! empty( $post_array['meta'] ) ) {
				\Distributor\Utils\set_meta( $new_post, $post_array['meta'] );
			}

			if ( ! empty( $post_array['terms'] ) ) {
				\Distributor\Utils\set_taxonomy_terms( $new_post, $post_array['terms'] );
			}

			if ( ! empty( $post_array['media'] ) ) {
				\Distributor\Utils\set_media( $new_post, $post_array['media'] );
			}

			/**
			 * Action triggered when a post is pulled via distributor.
			 *
			 * @param array              $new_post   The new post that was pulled.
			 * @param ExternalConnection $this       The distributor connection pulling the post.
			 * @param array              $post_array The original post data retrieved via the connection.
			 */
			do_action( 'dt_pull_post', $new_post, $this, $post_array );

			$created_posts[] = $new_post;
		}

		return $created_posts;
	}

	/**
	 * Push a post to an external connection
	 *
	 * @param  int   $post_id
	 * @param  array $args
	 * @since  0.8
	 * @return bool|\WP_Error
	 */
	public function push( $post_id, $args = array() ) {
		if ( empty( $post_id ) ) {
			return new \WP_Error( 'no-push-post-id', esc_html__( 'Post id required to push', 'distributor' ) );
		}

		$post = get_post( $post_id );

		$post_type = get_post_type( $post_id );

		$path = self::$namespace;

		/**
		 * First let's get the actual route. We don't know the "plural" of our post type
		 */

		$types_path = untrailingslashit( $this->base_url ) . '/' . $path . '/types';

		if ( function_exists( 'vip_safe_wp_remote_get' ) && \Distributor\Utils\is_vip_com() ) {
			$response = vip_safe_wp_remote_get(
				$types_path,
				false, 3, 3, 10, $this->auth_handler->format_get_args()
			);
		} else {
			$response = wp_remote_get(
				$types_path, $this->auth_handler->format_get_args(
					array(
						'timeout' => self::$timeout,
					)
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			return new \WP_Error( 'no-response-body', esc_html__( 'Response body is empty', 'distributor' ) );
		}

		$body_array = json_decode( $body, true );

		$type_url = $this->parse_type_items_link( $body_array[ $post_type ] );

		if ( empty( $type_url ) ) {
			return new \WP_Error( 'no-push-post-type', esc_html__( 'Could not determine remote post type endpoint', 'distributor' ) );
		}

		$signature = \Distributor\Subscriptions\generate_signature();

		/**
		 * Now let's push
		 */
		$post_body = [
			'title'                          => get_the_title( $post_id ),
			'slug'                           => $post->post_name,
			'content'                        => apply_filters( 'the_content', $post->post_content ),
			'type'                           => $post->post_type,
			'status'                         => ( ! empty( $args['post_status'] ) ) ? $args['post_status'] : 'publish',
			'excerpt'                        => $post->post_excerpt,
			'distributor_original_source_id' => $this->id,
			'distributor_original_site_name' => get_bloginfo( 'name' ),
			'distributor_original_site_url'  => home_url(),
			'distributor_original_post_url'  => get_permalink( $post_id ),
			'distributor_remote_post_id'     => $post_id,
			'distributor_signature'          => $signature,
			'distributor_media'              => \Distributor\Utils\prepare_media( $post_id ),
			'distributor_terms'              => \Distributor\Utils\prepare_taxonomy_terms( $post_id ),
			'distributor_meta'               => \Distributor\Utils\prepare_meta( $post_id ),
		];

		// Map to remote ID if a push has already happened
		if ( ! empty( $args['remote_post_id'] ) ) {
			$existing_post_url = untrailingslashit( $type_url ) . '/' . $args['remote_post_id'];

			// Check to make sure remote post still exists
			if ( function_exists( 'vip_safe_wp_remote_get' ) && \Distributor\Utils\is_vip_com() ) {
				$post_exists_response = vip_safe_wp_remote_get(
					$existing_post_url,
					false, 3, 3, 10, $this->auth_handler->format_get_args()
				);
			} else {
				$post_exists_response = wp_remote_get( $existing_post_url, $this->auth_handler->format_get_args( array( 'timeout' => self::$timeout ) ) );
			}

			if ( ! is_wp_error( $post_exists_response ) ) {
				$post_exists_response_code = wp_remote_retrieve_response_code( $post_exists_response );

				if ( 200 === (int) $post_exists_response_code ) {
					$type_url = $existing_post_url;
				}
			}
		}

		$response = wp_remote_post(
			$type_url, $this->auth_handler->format_post_args(
				array(
					/**
					 * Filter the timeout used when calling WordPressExternalConnection::push.
					 *
					 * @since 1.0
					 *
					 * @param int $timeout The timeout to use for the remote post. Default 5.
					 * @param object $post The post object
					 */
					'timeout' => apply_filters( 'dt_push_post_timeout', 45, $post ),
					/**
					 * Filter the arguments sent to the remote server during a push.
					 *
					 * @since 1.0
					 *
					 * @param  array              $post_body                      The request body to send.
					 * @param  object             $post                           The WP_Post that is being pushed.
					 * @param  ExternalConnection $this                           The distributor connection being pushed to.
					 */
					'body'    => apply_filters( 'dt_push_post_args', $post_body, $post, $this ),
				)
			)
		);

		/**
		 * Action triggered when a post is pushed via distributor.
		 *
		 * @param array              $response   The HTTP response of the push.
		 * @param array              $post_body  The body that was POSTed.
		 * @param string             $type_url   The URL that was POSTed to.
		 * @param int                $post_id    The post ID that was pushed.
		 * @param array              $args       The arguments sent with the POST.
		 * @param ExternalConnection $this       The distributor connection being pushed to.
		 */
		do_action( 'dt_push_post', $response, $post_body, $type_url, $post_id, $args, $this );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			return new \WP_Error( 'no-response-body', esc_html__( 'Response body is empty', 'distributor' ) );
		}

		$body_array = json_decode( $body, true );

		try {
			$remote_id = $body_array['id'];
		} catch ( \Exception $e ) {
			return new \WP_Error( 'no-push-post-remote-id', esc_html__( 'Could not determine remote post id.', 'distributor' ) );
		}

		$response_headers = wp_remote_retrieve_headers( $response );

		if ( ! empty( $response_headers['X-Distributor'] ) ) {
			// We have Distributor on the other side
			\Distributor\Subscriptions\create_subscription( $post_id, $remote_id, untrailingslashit( $this->base_url ), $signature );
		}

		return $remote_id;
	}

	/**
	 * Check what we can do with a given external connection (push or pull)
	 *
	 * @since  0.8
	 * @return array
	 */
	public function check_connections() {
		$output = array(
			'errors'              => array(),
			'can_post'            => array(),
			'can_get'             => array(),
			'endpoint_suggestion' => false,
		);

		if ( function_exists( 'vip_safe_wp_remote_get' ) && \Distributor\Utils\is_vip_com() ) {
			$response = vip_safe_wp_remote_get(
				untrailingslashit( $this->base_url ),
				false, 3, 3, 10, $this->auth_handler->format_get_args()
			);
		} else {
			$response = wp_remote_get( untrailingslashit( $this->base_url ), $this->auth_handler->format_get_args( array( 'timeout' => self::$timeout ) ) );
		}
		$body = wp_remote_retrieve_body( $response );

		if ( is_wp_error( $response ) || empty( $body ) ) {
			$output['errors']['no_external_connection'] = 'no_external_connection';
			return $output;
		}

		$data = json_decode( $body, true );

		if ( empty( $data ) ) {
			$output['errors']['no_external_connection'] = 'no_external_connection';
		}

		$response_headers = wp_remote_retrieve_headers( $response );
		$link_headers     = (array) $response_headers['Link'];
		$correct_endpoint = false;

		foreach ( $link_headers as $link_header ) {
			if ( strpos( $link_header, 'rel="https://api.w.org/"' ) !== false ) {
				$correct_endpoint = preg_replace( '#.*<([^>]+)>.*#', '$1', $link_header );
			}
		}

		if ( ! empty( $correct_endpoint ) && untrailingslashit( $this->base_url ) !== untrailingslashit( $correct_endpoint ) ) {
			$output['errors']['no_external_connection'] = 'no_external_connection';
			$output['endpoint_suggestion']              = untrailingslashit( $correct_endpoint );
		}

		if ( empty( $data['routes'] ) && empty( $output['errors']['no_external_connection'] ) ) {
			$output['errors']['no_types'] = 'no_types';
		}

		if ( ! empty( $output['errors'] ) ) {
			return $output;
		}

		if ( empty( $response_headers['X-Distributor'] ) ) {
			$output['errors']['no_distributor'] = 'no_distributor';
		}

		$routes = $data['routes'];

		$types_path = untrailingslashit( $this->base_url ) . '/' . self::$namespace . '/types';

		if ( function_exists( 'vip_safe_wp_remote_get' ) && \Distributor\Utils\is_vip_com() ) {
			$types_response = vip_safe_wp_remote_get(
				$types_path,
				false, 3, 3, 10, $this->auth_handler->format_get_args()
			);
		} else {
			$types_response = wp_remote_get( $types_path, $this->auth_handler->format_get_args( array( 'timeout' => self::$timeout ) ) );
		}
		$types_body = wp_remote_retrieve_body( $types_response );

		if ( is_wp_error( $types_response ) || empty( $types_body ) ) {
			$output['errors']['no_types'] = 'no_types';
		} else {
			$types = json_decode( $types_body, true );

			if ( 200 !== wp_remote_retrieve_response_code( $types_response ) || empty( $types ) ) {
				$output['errors']['no_types'] = 'no_types';
			} else {
				$can_get  = array();
				$can_post = array();

				$blacklisted_types = [ 'dt_subscription' ];

				foreach ( $types as $type_key => $type ) {

					if ( in_array( $type_key, $blacklisted_types, true ) ) {
						continue;
					}

					$link = $this->parse_type_items_link( $type );
					if ( empty( $link ) ) {
						continue;
					}

					$route = str_replace( untrailingslashit( $this->base_url ), '', $link );

					if ( ! empty( $routes[ $route ] ) ) {
						if ( in_array( 'GET', $routes[ $route ]['methods'], true ) ) {
							if ( function_exists( 'vip_safe_wp_remote_get' ) && \Distributor\Utils\is_vip_com() ) {
								$type_response = vip_safe_wp_remote_get(
									$link,
									false, 3, 3, 10, $this->auth_handler->format_get_args()
								);
							} else {
								$type_response = wp_remote_get( $link, $this->auth_handler->format_get_args( array( 'timeout' => self::$timeout ) ) );
							}

							if ( ! is_wp_error( $type_response ) ) {
								$code = (int) wp_remote_retrieve_response_code( $type_response );

								if ( 401 !== $code ) {
									$can_get[] = $type_key;
								}
							}
						}

						if ( in_array( 'POST', $routes[ $route ]['methods'], true ) ) {
							$type_response = wp_remote_post(
								$link, $this->auth_handler->format_post_args(
									array(
										'timeout' => self::$timeout,
										'body'    => array( 'test' => 1 ),
									)
								)
							);

							if ( ! is_wp_error( $type_response ) ) {
								$code = (int) wp_remote_retrieve_response_code( $type_response );

								if ( 401 !== $code ) {
									$can_post[] = $type_key;
								}
							}
						}
					}
				}

				$output['can_get']  = $can_get;
				$output['can_post'] = $can_post;
			}
		}

		return $output;
	}

	/**
	 * Convert object to WP_Post
	 *
	 * @param  array
	 * @since  0.8
	 * @return \WP_Post
	 */
	private function to_wp_post( $post ) {
		$obj = new \stdClass();

		$obj->ID           = $post['id'];
		$obj->post_title   = $post['title']['rendered'];
		$obj->post_content = $post['content']['rendered'];

		if ( isset( $post['excerpt']['raw'] ) ) {
			$obj->post_excerpt = $post['excerpt']['raw'];
		} else {
			$obj->post_excerpt = $post['excerpt']['rendered'];
		}

		$obj->post_status       = 'draft';
		$obj->post_date         = $post['date'];
		$obj->post_date_gmt     = $post['date_gmt'];
		$obj->guid              = $post['guid']['rendered'];
		$obj->post_modified     = $post['modified'];
		$obj->post_modified_gmt = $post['modified_gmt'];
		$obj->post_type         = $post['type'];
		$obj->link              = $post['link'];
		$obj->post_author       = get_current_user_id();

		/**
		 * These will only be set if Distributor is active on the other side
		 */
		$obj->meta               = ( ! empty( $post['distributor_meta'] ) ) ? $post['distributor_meta'] : [];
		$obj->terms              = ( ! empty( $post['distributor_terms'] ) ) ? $post['distributor_terms'] : [];
		$obj->media              = ( ! empty( $post['distributor_media'] ) ) ? $post['distributor_media'] : [];
		$obj->original_site_name = ( ! empty( $post['distributor_original_site_name'] ) ) ? $post['distributor_original_site_name'] : null;
		$obj->original_site_url  = ( ! empty( $post['distributor_original_site_url'] ) ) ? $post['distributor_original_site_url'] : null;

		$obj->full_connection = ( ! empty( $post['full_connection'] ) );

		/**
		 * Filter the post item.
		 *
		 * @since 1.0
		 *
		 * @param  object             $obj  The WP_Post that is being pushed.
		 * @param  ExternalConnection $this The external connection the post concerns.
		 */
		return apply_filters( 'dt_item_mapping', new \WP_Post( $obj ), $post, $this );
	}

	/**
	 * Setup actions and filters that are need on every page load
	 *
	 * @since 1.0
	 */
	public static function bootstrap() {
		add_action( 'template_redirect', array( '\Distributor\ExternalConnections\WordPressExternalConnection', 'canonicalize_front_end' ) );
	}

	/**
	 * Setup canonicalization on front end
	 *
	 * @since  1.0
	 */
	public static function canonicalize_front_end() {
		add_filter( 'get_canonical_url', array( '\Distributor\ExternalConnections\WordPressExternalConnection', 'canonical_url' ), 10, 2 );
		add_filter( 'wpseo_canonical', array( '\Distributor\ExternalConnections\WordPressExternalConnection', 'wpseo_canonical_url' ) );
		add_filter( 'the_author', array( '\Distributor\ExternalConnections\WordPressExternalConnection', 'the_author_distributed' ) );
		add_filter( 'author_link', array( '\Distributor\ExternalConnections\WordPressExternalConnection', 'author_posts_url_distributed' ), 10, 3 );
	}

	/**
	 * Override author with site name on distributed post
	 *
	 * @param  string $author
	 * @since  1.0
	 * @return string
	 */
	public static function author_posts_url_distributed( $link, $author_id, $author_nicename ) {
		global $post;

		if ( empty( $post ) ) {
			return $link;
		}

		$settings = Utils\get_settings();

		if ( empty( $settings['override_author_byline'] ) ) {
			return $link;
		}

		$original_source_id = get_post_meta( $post->ID, 'dt_original_source_id', true );
		$original_site_url  = get_post_meta( $post->ID, 'dt_original_site_url', true );
		$unlinked           = (bool) get_post_meta( $post->ID, 'dt_unlinked', true );

		if ( empty( $original_source_id ) || empty( $original_site_url ) || $unlinked ) {
			return $link;
		}

		return $original_site_url;
	}

	/**
	 * Override author with site name on distributed post
	 *
	 * @param  string $author
	 * @since  1.0
	 * @return string
	 */
	public static function the_author_distributed( $author ) {
		global $post;

		if ( empty( $post ) ) {
			return $author;
		}

		$settings = Utils\get_settings();

		if ( empty( $settings['override_author_byline'] ) ) {
			return $author;
		}

		$original_source_id = get_post_meta( $post->ID, 'dt_original_source_id', true );
		$original_site_name = get_post_meta( $post->ID, 'dt_original_site_name', true );
		$original_site_url  = get_post_meta( $post->ID, 'dt_original_site_url', true );
		$unlinked           = (bool) get_post_meta( $post->ID, 'dt_unlinked', true );

		if ( empty( $original_source_id ) || empty( $original_site_url ) || empty( $original_site_name ) || $unlinked ) {
			return $author;
		}

		return $original_site_name;
	}

	/**
	 * Make sure canonical url header is outputted
	 *
	 * @param  string $canonical_url
	 * @param  object $post
	 * @since  1.0
	 * @return string
	 */
	public static function canonical_url( $canonical_url, $post ) {
		$original_source_id = get_post_meta( $post->ID, 'dt_original_source_id', true );
		$original_post_url  = get_post_meta( $post->ID, 'dt_original_post_url', true );
		$unlinked           = (bool) get_post_meta( $post->ID, 'dt_unlinked', true );
		$original_deleted   = (bool) get_post_meta( $post->ID, 'dt_original_post_deleted', true );

		if ( empty( $original_source_id ) || empty( $original_post_url ) || $unlinked || $original_deleted ) {
			return $canonical_url;
		}

		return $original_post_url;
	}

	/**
	 * Handles the canonical URL change for distributed content when Yoast SEO is in use
	 *
	 * @param string $canonical_url The Yoast WPSEO deduced canonical URL
	 * @since  1.0
	 * @return string $canonical_url The updated distributor friendly URL
	 */
	public static function wpseo_canonical_url( $canonical_url ) {

		// Return as is if not on a singular page - taken from rel_canonical()
		if ( ! is_singular() ) {
			return $canonical_url;
		}

		$id = get_queried_object_id();

		// Return as is if we do not have a object id for context - taken from rel_canonical()
		if ( 0 === $id ) {
			return $canonical_url;
		}

		$post = get_post( $id );

		// Return as is if we don't have a valid post object - taken from wp_get_canonical_url()
		if ( ! $post ) {
			return $canonical_url;
		}

		// Return as is if current post is not published - taken from wp_get_canonical_url()
		if ( 'publish' !== $post->post_status ) {
			return $canonical_url;
		}

		return self::canonical_url( $canonical_url, $post );
	}
}
