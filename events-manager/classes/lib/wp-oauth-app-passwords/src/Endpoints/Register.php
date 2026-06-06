<?php
namespace Pixelite\OAuth_App_Passwords;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * /register — minimal Dynamic Client Registration (RFC 7591).
 *
 * Public clients only (no secret). Lets MCP clients such as Claude register themselves automatically rather than requiring an admin to pre-create a client. Registration is unauthenticated by design (that is how the RFC's open-registration profile works); abuse is bounded because a registered client can do nothing until a real WordPress user completes the consent flow, and the registry is size-capped and de-duplicated.
 */
class Register {

	public static function handle( \WP_REST_Request $request ) {
		if ( ! (bool) apply_filters( 'pixelite_oauth_allow_dynamic_registration', true ) ) {
			return new \WP_Error( 'registration_not_supported', __( 'Dynamic client registration is disabled.', 'wp-oauth-app-passwords' ), array( 'status' => 403 ) );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}

		$redirect_uris = (array) ( $body['redirect_uris'] ?? array() );
		$redirect_uris = array_values( array_filter( array_map( array( Support::class, 'sanitize_redirect_uri' ), $redirect_uris ) ) );
		if ( empty( $redirect_uris ) ) {
			return new \WP_Error( 'invalid_redirect_uri', __( 'At least one redirect_uri is required.', 'wp-oauth-app-passwords' ), array( 'status' => 400 ) );
		}

		$client = Clients::register( array(
			'name'          => (string) ( $body['client_name'] ?? '' ),
			'redirect_uris' => $redirect_uris,
			'scopes'        => isset( $body['scope'] ) ? explode( ' ', (string) $body['scope'] ) : array( Server::default_scope() ),
			'dynamic'       => true,
		) );

		$response = new \WP_REST_Response( array(
			'client_id'                  => $client['client_id'],
			'client_name'               => $client['name'],
			'redirect_uris'             => $client['redirect_uris'],
			'token_endpoint_auth_method' => 'none',
			'grant_types'               => array( 'authorization_code', 'refresh_token' ),
			'response_types'            => array( 'code' ),
			'scope'                     => implode( ' ', (array) $client['scopes'] ),
		), 201 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
