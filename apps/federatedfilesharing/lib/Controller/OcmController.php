<?php
/**
 * @author Viktar Dubiniuk <dubiniuk@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\FederatedFileSharing\Controller;

use OCA\FederatedFileSharing\Address;
use OCA\FederatedFileSharing\AddressHandler;
use OCA\FederatedFileSharing\FederatedShareProvider;
use OCA\FederatedFileSharing\Ocm\Exception\BadRequestException;
use OCA\FederatedFileSharing\Ocm\Exception\ForbiddenException;
use OCA\FederatedFileSharing\Ocm\Exception\NotImplementedException;
use OCA\FederatedFileSharing\Ocm\Notification\FileNotification;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCA\FederatedFileSharing\FedShareManager;
use OCA\FederatedFileSharing\Ocm\Exception\OcmException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Share;
use OCP\Share\IShare;

/**
 * Class OcmController
 *
 * @package OCA\FederatedFileSharing\Controller
 */
class OcmController extends Controller {
	const API_VERSION = '1.0-proposal1';

	/**
	 * @var FederatedShareProvider
	 */
	private $federatedShareProvider;

	/**
	 * @var IURLGenerator
	 */
	protected $urlGenerator;

	/**
	 * @var IAppManager
	 */
	private $appManager;

	/**
	 * @var IUserManager
	 */
	protected $userManager;

	/**
	 * @var AddressHandler
	 */
	protected $addressHandler;

	/**
	 * @var FedShareManager
	 */
	protected $fedShareManager;

	/**
	 * @var ILogger
	 */
	protected $logger;

	/**
	 * OcmController constructor.
	 *
	 * @param string $appName
	 * @param IRequest $request
	 * @param FederatedShareProvider $federatedShareProvider
	 * @param IURLGenerator $urlGenerator
	 * @param IAppManager $appManager
	 * @param IUserManager $userManager
	 * @param AddressHandler $addressHandler
	 * @param FedShareManager $fedShareManager
	 * @param ILogger $logger
	 */
	public function __construct($appName,
									IRequest $request,
									FederatedShareProvider $federatedShareProvider,
									IURLGenerator $urlGenerator,
									IAppManager $appManager,
									IUserManager $userManager,
									AddressHandler $addressHandler,
									FedShareManager $fedShareManager,
									ILogger $logger
	) {
		parent::__construct($appName, $request);

		$this->federatedShareProvider = $federatedShareProvider;
		$this->urlGenerator = $urlGenerator;
		$this->appManager = $appManager;
		$this->userManager = $userManager;
		$this->addressHandler = $addressHandler;
		$this->fedShareManager = $fedShareManager;
		$this->logger = $logger;
	}

	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * EndPoint discovery
	 * Responds to /ocm-provider/ requests
	 *
	 * @return array
	 */
	public function discovery() {
		return [
			'enabled' => true,
			'apiVersion' => self::API_VERSION,
			'endPoint' => $this->urlGenerator->linkToRouteAbsolute(
				"{$this->appName}.ocm.index"
			),
			'shareTypes' => [
				'name' => FileNotification::RESOURCE_TYPE_FILE,
				'protocols' => $this->getProtocols()
			]
		];
	}

	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $shareWith identifier of the user or group
	 * 							to share the resource with
	 * @param string $name name of the shared resource
	 * @param string $description share description (optional)
	 * @param string $providerId Identifier of the resource at the provider side
	 * @param string $owner identifier of the user that owns the resource
	 * @param string $ownerDisplayName display name of the owner
	 * @param string $sender Provider specific identifier of the user that wants
	 *							to share the resource
	 * @param string $senderDisplayName Display name of the user that wants
	 * 									to share the resource
	 * @param string $shareType Share type ('user' is supported atm)
	 * @param string $resourceType only 'file' is supported atm
	 * @param array $protocol
	 * 		[
	 * 			'name' => (string) protocol name. Only 'webdav' is supported atm,
	 * 			'options' => [
	 * 				protocol specific options
	 * 				only `webdav` options are supported atm
	 * 				e.g. `uri`,	`access_token`, `password`, `permissions` etc.
	 *
	 * 				For backward compatibility the webdav protocol will use
	 * 				the 'sharedSecret" as username and password
	 * 			]
	 *
	 * @return JSONResponse
	 */
	public function createShare($shareWith,
								$name,
								$description,
								$providerId,
								$owner,
								$ownerDisplayName,
								$sender,
								$senderDisplayName,
								$shareType,
								$resourceType,
								$protocol

	) {
		try {
			$this->assertIncomingSharingEnabled();
			$hasMissingParams = $this->hasNull(
				[$shareWith, $name, $providerId, $owner, $shareType, $resourceType]
			);
			if ($hasMissingParams
				|| !\is_array($protocol)
				|| !isset($protocol['name'])
				|| !isset($protocol['options'])
				|| !\is_array($protocol['options'])
				|| !isset($protocol['options']['sharedSecret'])
			) {
				throw new BadRequestException(
					'server can not add remote share, missing parameter'
				);
			}
			if (!\OCP\Util::isValidFileName($name)) {
				throw new BadRequestException(
					'The mountpoint name contains invalid characters.'
				);
			}

			$shareWithAddress = new Address($shareWith);
			$localShareWith = $shareWithAddress->getUserId();

			// FIXME this should be a method in the user management instead
			$this->logger->debug(
				"shareWith before, $localShareWith",
				['app' => $this->appName]
			);
			\OCP\Util::emitHook(
				'\OCA\Files_Sharing\API\Server2Server',
				'preLoginNameUsedAsUserName',
				['uid' => &$localShareWith]
			);
			$this->logger->debug(
				"shareWith after, $localShareWith",
				['app' => $this->appName]
			);

			if ($this->isSupportedProtocol($protocol['name']) === false) {
				throw new NotImplementedException(
					"Protocol {$protocol['name']} is not supported"
				);
			}

			if ($this->isSupportedShareType($shareType) === false) {
				throw new NotImplementedException(
					"ShareType {$shareType} is not supported"
				);
			}

			if ($this->isSupportedResourceType($resourceType) === false) {
				throw new NotImplementedException(
					"ResourceType {$resourceType} is not supported"
				);
			}

			if (!$this->userManager->userExists($localShareWith)) {
				throw new BadRequestException("User $localShareWith does not exist");
			}

			$ownerAddress = new Address($owner, $ownerDisplayName);
			$sharedByAddress = new Address($sender, $senderDisplayName);

			$this->fedShareManager->createShare(
				$ownerAddress,
				$sharedByAddress,
				$localShareWith,
				$providerId,
				$name,
				$protocol['options']['sharedSecret']
			);
		} catch (OcmException $e) {
			return new JSONResponse(
				['message' => $e->getMessage()],
				$e->getHttpStatusCode()
			);
		} catch (\Exception $e) {
			$this->logger->error(
				"server can not add remote share, {$e->getMessage()}",
				['app' => 'federatefilesharing']
			);
			return new JSONResponse(
				[
					'message' => "internal server error, was not able to add share from {$ownerAddress->getHostName()}"
				],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
		return new JSONResponse(
			[],
			Http::STATUS_CREATED
		);
	}

	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 *
	 * @param string $notificationType notification type (SHARE_REMOVED, etc)
	 * @param string $resourceType only 'file' is supported atm
	 * @param string $providerId Identifier of the resource at the provider side
	 * @param array $notification
	 * 		[
	 * 			optional additional parameters, depending on the notification
	 * 				and the resource type
	 * 		]
	 *
	 * @return JSONResponse
	 */
	public function processNotification($notificationType,
										$resourceType,
										$providerId,
										$notification
	) {
		try {
			$hasMissingParams = $this->hasNull(
				[$notificationType, $resourceType, $providerId]
			);
			if ($hasMissingParams || !\is_array($notification)) {
				throw new BadRequestException(
					'server can not add remote share, missing parameter'
				);
			}

			if ($this->isSupportedResourceType($resourceType) === false) {
				throw new NotImplementedException(
					"ResourceType {$resourceType} is not supported"
				);
			}
			// TODO: check permissions/preconditions in all cases
			switch ($notificationType) {
				case FileNotification::NOTIFICATION_TYPE_SHARE_ACCEPTED:
					$share = $this->getValidShare(
						$providerId, $notification['sharedSecret']
					);
					$this->fedShareManager->acceptShare($share);
					break;
				case FileNotification::NOTIFICATION_TYPE_SHARE_DECLINED:
					$share = $this->getValidShare(
						$providerId, $notification['sharedSecret']
					);
					$this->fedShareManager->declineShare($share);
					break;
				case FileNotification::NOTIFICATION_TYPE_REQUEST_RESHARE:
					$shareWithAddress = new Address($notification['shareWith']);
					$localShareWith = $shareWithAddress->getUserId();
					$share = $this->getValidShare(
						$providerId, $notification['sharedSecret']
					);
					// TODO: permissions not needed ???
					$this->fedShareManager->reShare(
						$share, $providerId, $localShareWith, 0
					);
					break;
				case FileNotification::NOTIFICATION_TYPE_RESHARE_CHANGE_PERMISSION:
					$permissions = $notification['permission'];
					// TODO: Map OCM permissions to numeric
					$share = $this->getValidShare(
						$providerId, $notification['sharedSecret']
					);
					$this->fedShareManager->updatePermissions($share, $permissions);
					break;
				case FileNotification::NOTIFICATION_TYPE_SHARE_UNSHARED:
					$this->fedShareManager->unshare(
						$providerId, $notification['sharedSecret']
					);
					break;
				case FileNotification::NOTIFICATION_TYPE_RESHARE_UNDO:
					$share = $this->getValidShare(
						$providerId, $notification['sharedSecret']
					);
					$this->fedShareManager->revoke($share);
					break;
				default:
					return new JSONResponse(
						['message' => "Notification of type {$notificationType} is not supported"],
						Http::STATUS_NOT_IMPLEMENTED
					);
			}
		} catch (OcmException $e) {
			return new JSONResponse(
				['message' => $e->getMessage()],
				$e->getHttpStatusCode()
			);
		} catch (Share\Exceptions\ShareNotFound $e) {
			return new JSONResponse(
				['message' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (\Exception $e) {
			$this->logger->error(
				"server can not process notification, {$e->getMessage()}",
				['app' => 'federatefilesharing']
			);
			return new JSONResponse(
				[
					'message' => "internal server error, was not able to process notification"
				],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
		return new JSONResponse(
			[],
			Http::STATUS_CREATED
		);
	}

	/**
	 * Get list of supported protocols
	 *
	 * @return array
	 */
	protected function getProtocols() {
		return [
			'webdav' => '/public.php/webdav/'
		];
	}

	/**
	 * Make sure that incoming shares are enabled
	 *
	 * @return void
	 *
	 * @throws NotImplementedException
	 */
	protected function assertIncomingSharingEnabled() {
		if (!$this->appManager->isEnabledForUser('files_sharing')
			|| !$this->federatedShareProvider->isIncomingServer2serverShareEnabled()
		) {
			throw new NotImplementedException();
		}
	}

	/**
	 * Make sure that outgoing shares are enabled
	 *
	 * @return void
	 *
	 * @throws NotImplementedException
	 */
	protected function assertOutgoingSharingEnabled() {
		if (!$this->appManager->isEnabledForUser('files_sharing')
			|| !$this->federatedShareProvider->isOutgoingServer2serverShareEnabled()
		) {
			throw new NotImplementedException();
		}
	}

	/**
	 * Check if value is null or an array has any null item
	 *
	 * @param mixed $param
	 *
	 * @return bool
	 */
	protected function hasNull($param) {
		if (\is_array($param)) {
			return \in_array(null, $param, true);
		} else {
			return $param === null;
		}
	}

	/**
	 * Get share by id, validate it's type and token
	 *
	 * @param int $id
	 * @param string $sharedSecret
	 *
	 * @return IShare
	 *
	 * @throws BadRequestException
	 * @throws ForbiddenException
	 * @throws Share\Exceptions\ShareNotFound
	 */
	protected function getValidShare($id, $sharedSecret) {
		$share = $this->federatedShareProvider->getShareById($id);
		if ($share->getShareType() !== FederatedShareProvider::SHARE_TYPE_REMOTE) {
			throw new BadRequestException("Share with id {$id} des not exist");
		}

		if ($share->getToken() !== $sharedSecret) {
			throw new ForbiddenException("The secret doesn not match");
		}
		return $share;
	}

	/**
	 * @param string $resourceType
	 *
	 * @return bool
	 */
	protected function isSupportedResourceType($resourceType) {
		return $resourceType === FileNotification::RESOURCE_TYPE_FILE;
	}

	/**
	 * @param string $shareType
	 * @return bool
	 */
	protected function isSupportedShareType($shareType) {
		// TODO: make it a constant
		return $shareType === 'user';
	}

	/**
	 * @param string $protocolName
	 * @return bool
	 */
	protected function isSupportedProtocol($protocolName) {
		$supportedProtocols = $this->getProtocols();
		$supportedProtocolNames = \array_keys($supportedProtocols);
		return \in_array($protocolName, $supportedProtocolNames);
	}
}
