<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Controller\Api;

use ApiPlatform\Validator\ValidatorInterface;
use Chamilo\CoreBundle\Dto\CreateUserOnAccessUrlInput;
use Chamilo\CoreBundle\Entity\AccessUrlRelUser;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CoreBundle\Repository\ExtraFieldRepository;
use Chamilo\CoreBundle\Repository\ExtraFieldValuesRepository;
use Chamilo\CoreBundle\Repository\Node\AccessUrlRepository;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsController]
class CreateUserOnAccessUrlAction
{
    public function __construct(
        private EntityManagerInterface $em,
        private AccessUrlRepository $accessUrlRepo,
        private ValidatorInterface $validator,
        private UserPasswordHasherInterface $passwordHasher,
        private ExtraFieldValuesRepository $extraFieldValuesRepo,
        private ExtraFieldRepository $extraFieldRepo
    ) {}

    public function __invoke(CreateUserOnAccessUrlInput $data): User
    {
        $this->validator->validate($data);

        $url = $this->accessUrlRepo->find($data->getAccessUrlId());
        if (!$url) {
            throw new NotFoundHttpException('Access URL not found.');
        }

        $user = new User();
        $user
            ->setUsername($data->getUsername())
            ->setFirstname($data->getFirstname())
            ->setLastname($data->getLastname())
            ->setEmail($data->getEmail())
            ->setLocale($data->getLocale() ?? 'en')
            ->setTimezone($data->getTimezone() ?? 'Europe/Paris')
            ->setStatus($data->getStatus() ?? 5)
            ->setPassword(
                $this->passwordHasher->hashPassword($user, $data->getPassword())
            )
        ;

        $this->em->persist($user);
        $this->em->flush();

        if (!empty($data->extraFields)) {
            foreach ($data->extraFields as $variable => $value) {
                $extraField = $this->extraFieldRepo->findOneBy([
                    'variable' => $variable,
                    'itemType' => 1,
                ]);

                if (!$extraField) {
                    throw new RuntimeException("ExtraField '{$variable}' not found for users.");
                }

                $this->extraFieldValuesRepo->updateItemData(
                    $extraField,
                    $user,
                    $value
                );
            }
        }

        $rel = new AccessUrlRelUser();
        $rel->setUser($user)->setUrl($url);

        $this->em->persist($rel);
        $this->em->flush();

        return $user;
    }
}
