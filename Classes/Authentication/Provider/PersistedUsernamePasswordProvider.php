<?php
namespace Neos\Security\Authentication\Provider;

/*
 * This file is part of the Neos.Flow package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Security\Account;
use Neos\Security\AccountRepository;
use Neos\Security\Authentication\Token\UsernamePassword;
use Neos\Security\Authentication\Token\UsernamePasswordHttpBasic;
use Neos\Security\Authentication\TokenInterface;
use Neos\Security\Context;
use Neos\Security\Cryptography\HashService;
use Neos\Security\Exception\UnsupportedAuthenticationTokenException;

/**
 * An authentication provider that authenticates
 * Neos\Security\Authentication\Token\UsernamePassword tokens.
 * The accounts are stored in the Content Repository.
 */
class PersistedUsernamePasswordProvider extends AbstractProvider
{
    /**
     * @var AccountRepository
     * @Flow\Inject
     */
    protected $accountRepository;

    /**
     * @var HashService
     * @Flow\Inject
     */
    protected $hashService;

    /**
     * @var Context
     * @Flow\Inject
     */
    protected $securityContext;

    /**
     * @var \Neos\Flow\Persistence\PersistenceManagerInterface
     * @Flow\Inject
     */
    protected $persistenceManager;

    /**
     * Returns the class names of the tokens this provider can authenticate.
     *
     * @return array
     */
    public function getTokenClassNames()
    {
        return [UsernamePassword::class, UsernamePasswordHttpBasic::class];
    }

    /**
     * Checks the given token for validity and sets the token authentication status
     * accordingly (success, wrong credentials or no credentials given).
     *
     * @param TokenInterface $authenticationToken The token to be authenticated
     * @return void
     * @throws UnsupportedAuthenticationTokenException
     */
    public function authenticate(TokenInterface $authenticationToken)
    {
        if (!($authenticationToken instanceof UsernamePassword)) {
            throw new UnsupportedAuthenticationTokenException('This provider cannot authenticate the given token.', 1217339840);
        }

        /** @var $account Account */
        $account = null;
        $credentials = $authenticationToken->getCredentials();

        if ($authenticationToken->getAuthenticationStatus() !== TokenInterface::AUTHENTICATION_SUCCESSFUL) {
            $authenticationToken->setAuthenticationStatus(TokenInterface::NO_CREDENTIALS_GIVEN);
        }

        if (!is_array($credentials) || !isset($credentials['username']) || !isset($credentials['password'])) {
            return;
        }

        $providerName = $this->name;
        $accountRepository = $this->accountRepository;
        $this->securityContext->withoutAuthorizationChecks(function () use ($credentials, $providerName, $accountRepository, &$account) {
            $account = $accountRepository->findActiveByAccountIdentifierAndAuthenticationProviderName($credentials['username'], $providerName);
        });

        $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);

        if ($account === null) {
            $this->hashService->validatePassword($credentials['password'], 'bcrypt=>$2a$14$DummySaltToPreventTim,.ingAttacksOnThisProvider');
            return;
        }

        if ($this->hashService->validatePassword($credentials['password'], $account->getCredentialsSource())) {
            $account->authenticationAttempted(TokenInterface::AUTHENTICATION_SUCCESSFUL);
            $authenticationToken->setAuthenticationStatus(TokenInterface::AUTHENTICATION_SUCCESSFUL);
            $authenticationToken->setAccount($account);
        } else {
            $account->authenticationAttempted(TokenInterface::WRONG_CREDENTIALS);
        }
        $this->accountRepository->update($account);
        $this->persistenceManager->whitelistObject($account);
    }
}
