<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Vpn\Portal\Http\Auth;

use fkooman\Radius\ClientConfig;
use fkooman\Radius\Exception\AccessChallengeException;
use fkooman\Radius\Exception\AccessRejectException;
use fkooman\Radius\RadiusClient;
use fkooman\Radius\RadiusPacket;
use Vpn\Portal\Cfg\RadiusAuthConfig;
use Vpn\Portal\Hex;
use Vpn\Portal\Http\Auth\Exception\CredentialValidatorChallengeException;
use Vpn\Portal\Http\Auth\Exception\CredentialValidatorException;
use Vpn\Portal\Http\SessionInterface;
use Vpn\Portal\Http\UserInfo;
use Vpn\Portal\LoggerInterface;

final class RadiusCredentialValidator implements CredentialValidatorInterface
{
    private const RADIUS_CHALLENGE_STATE_SESSION_KEY = '__radius_challenge_state';

    public function __construct(private LoggerInterface $logger, private RadiusAuthConfig $radiusAuthConfig, private SessionInterface $session) {}

    #[\Override]
    public function validate(string $authUser, string $authPass): UserInfo
    {
        // add realm when requested
        if (null !== $radiusRealm = $this->radiusAuthConfig->radiusRealm()) {
            $authUser = \sprintf('%s@%s', $authUser, $radiusRealm);
        }

        $radiusAuth = new RadiusClient(
            new ClientConfig(
                $this->radiusAuthConfig->nasIdentifier() ?? 'VPN Server',
                $this->radiusAuthConfig->requireMessageAuthenticator()
            ),
            new RadiusLogger($this->logger)
        );

        foreach ($this->radiusAuthConfig->serverList() as $serverInfo) {
            $radiusAuth->addServer($serverInfo);
        }

        try {
            $accessChallengeResponse = null;
            if (null !== $radiusPacketHexBytes = $this->session->get(self::RADIUS_CHALLENGE_STATE_SESSION_KEY)) {
                $this->session->remove(self::RADIUS_CHALLENGE_STATE_SESSION_KEY);
                // we make sure the "authUser" is the same as the current
                // authUser, not sure if this is very important, the server
                // MUST prevent this, but just making sure also here...
                if (str_starts_with($radiusPacketHexBytes, $authUser . '!')) {
                    $radiusPacketHexBytes = substr($radiusPacketHexBytes, \strlen($authUser) + 1);
                    $accessChallengeResponse = RadiusPacket::fromBytes(Hex::decode($radiusPacketHexBytes));
                }
            }

            $accessResponse = $radiusAuth->accessRequest($authUser, $authPass, $accessChallengeResponse);
            $permissionAttributeList = $this->radiusAuthConfig->permissionAttributeList();
            if (null !== $cfgPermissionAttribute = $this->radiusAuthConfig->permissionAttribute()) {
                // add the legacy `int` to the list of attributes to retrieve
                $permissionAttributeList[] = $cfgPermissionAttribute;
            }

            // retrieve all attributes we'd like
            $permissionList = [];
            foreach ($permissionAttributeList as $permissionAttribute) {
                foreach ($accessResponse->attributeCollection()->get($permissionAttribute) as $attributeValue) {
                    $permissionList[] = \sprintf('%s!%s', $permissionAttribute, $attributeValue);
                }
            }

            // if the RADIUS server provides us with a User-Name attribute, we
            // use as it might have been "normalized". May be relevant when
            // the RADIUS uses an LDAP server as backend...
            if (null !== $userName = $accessResponse->attributeCollection()->getOne('User-Name')) {
                $authUser = $userName;
            }

            return new UserInfo($authUser, $permissionList);
        } catch (AccessChallengeException $e) {
            // the RADIUS server is asking for a response to a "Challenge"
            // we store the RADIUS "Accept-Challenge" packet, so we can use
            // it later to send a new Access-Request with the response to the
            // challenge and the "State".
            $this->session->set(
                self::RADIUS_CHALLENGE_STATE_SESSION_KEY,
                $authUser . '!' . Hex::encode($e->radiusPacket()->toBytes())
            );

            // we set a empty value for "Reply-Message" in case the RADIUS
            // server does not provide one... this is not ideal, but can
            // be fixed by having the RADIUS server add a Reply-Message to the
            // Accept-Challenge response. In the "userPassAuth" template we
            // then set a default text that can be translated...
            $replyMessage = '';
            if (null !== $replyMessages = $e->radiusPacket()->attributeCollection()->get('Reply-Message')) {
                // if case there is >1 Reply-Message we add them all together
                // separated by a comma
                $replyMessage = implode(', ', $replyMessages);
            }

            throw new CredentialValidatorChallengeException($replyMessage);
        } catch (AccessRejectException $e) {
            throw new CredentialValidatorException($e->getMessage());
        }
    }
}
