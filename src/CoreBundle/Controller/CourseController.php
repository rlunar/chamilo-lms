<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Controller;

use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\CourseRelUser;
use Chamilo\CoreBundle\Entity\ExtraField;
use Chamilo\CoreBundle\Entity\SequenceResource;
use Chamilo\CoreBundle\Entity\Session;
use Chamilo\CoreBundle\Entity\SessionRelUser;
use Chamilo\CoreBundle\Entity\Tag;
use Chamilo\CoreBundle\Entity\Tool;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CoreBundle\Framework\Container;
use Chamilo\CoreBundle\Helpers\AccessUrlHelper;
use Chamilo\CoreBundle\Helpers\CourseHelper;
use Chamilo\CoreBundle\Helpers\UserHelper;
use Chamilo\CoreBundle\Repository\AssetRepository;
use Chamilo\CoreBundle\Repository\CourseCategoryRepository;
use Chamilo\CoreBundle\Repository\ExtraFieldValuesRepository;
use Chamilo\CoreBundle\Repository\LanguageRepository;
use Chamilo\CoreBundle\Repository\LegalRepository;
use Chamilo\CoreBundle\Repository\Node\CourseRepository;
use Chamilo\CoreBundle\Repository\Node\IllustrationRepository;
use Chamilo\CoreBundle\Repository\SequenceResourceRepository;
use Chamilo\CoreBundle\Repository\TagRepository;
use Chamilo\CoreBundle\Security\Authorization\Voter\CourseVoter;
use Chamilo\CoreBundle\Settings\SettingsManager;
use Chamilo\CoreBundle\Tool\ToolChain;
use Chamilo\CourseBundle\Controller\ToolBaseController;
use Chamilo\CourseBundle\Entity\CCourseDescription;
use Chamilo\CourseBundle\Entity\CLink;
use Chamilo\CourseBundle\Entity\CShortcut;
use Chamilo\CourseBundle\Entity\CTool;
use Chamilo\CourseBundle\Entity\CToolIntro;
use Chamilo\CourseBundle\Repository\CCourseDescriptionRepository;
use Chamilo\CourseBundle\Repository\CLpRepository;
use Chamilo\CourseBundle\Repository\CQuizRepository;
use Chamilo\CourseBundle\Repository\CShortcutRepository;
use Chamilo\CourseBundle\Repository\CToolRepository;
use Chamilo\CourseBundle\Settings\SettingsCourseManager;
use Chamilo\CourseBundle\Settings\SettingsFormFactory;
use CourseManager;
use Database;
use Display;
use Doctrine\ORM\EntityManagerInterface;
use Event;
use Exception;
use Exercise;
use ExtraFieldValue;
use Graphp\GraphViz\GraphViz;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Exception\ValidatorException;
use Symfony\Contracts\Translation\TranslatorInterface;
use UserManager;

/**
 * @author Julio Montoya <gugli100@gmail.com>
 */
#[Route('/course')]
class CourseController extends ToolBaseController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SerializerInterface $serializer,
        private readonly UserHelper $userHelper,
    ) {}

    #[IsGranted('ROLE_USER')]
    #[Route('/{cid}/checkLegal.json', name: 'chamilo_core_course_check_legal_json')]
    public function checkTermsAndConditionJson(
        Request $request,
        LegalRepository $legalTermsRepo,
        LanguageRepository $languageRepository,
        ExtraFieldValuesRepository $extraFieldValuesRepository,
        SettingsManager $settingsManager
    ): Response {
        $user = $this->userHelper->getCurrent();
        $course = $this->getCourse();
        $responseData = [
            'redirect' => false,
            'url' => '#',
        ];

        if ($user->hasRole('ROLE_STUDENT')
            && 'true' === $settingsManager->getSetting('registration.allow_terms_conditions')
            && 'course' === $settingsManager->getSetting('platform.load_term_conditions_section')
        ) {
            $termAndConditionStatus = false;
            $extraValue = $extraFieldValuesRepository->findLegalAcceptByItemId($user->getId());
            if (!empty($extraValue['value'])) {
                $result = $extraValue['value'];
                $userConditions = explode(':', $result);
                $version = $userConditions[0];
                $langId = (int) $userConditions[1];
                $realVersion = $legalTermsRepo->getLastVersion($langId);
                $termAndConditionStatus = ($version >= $realVersion);
            }

            if (false === $termAndConditionStatus) {
                $request->getSession()->set('term_and_condition', ['user_id' => $user->getId()]);

                $redirect = true;

                if ('true' === $settingsManager->getSetting('course.allow_public_course_with_no_terms_conditions')
                    && Course::OPEN_WORLD === $course->getVisibility()
                ) {
                    $redirect = false;
                }

                if ($redirect && !$this->isGranted('ROLE_ADMIN')) {
                    $request->getSession()->remove('cid');
                    $request->getSession()->remove('course');
                    $responseData = [
                        'redirect' => true,
                        'url' => '/main/auth/inscription.php',
                    ];
                }
            } else {
                $request->getSession()->remove('term_and_condition');
            }
        }

        return new JsonResponse($responseData);
    }

    #[Route('/{cid}/home.json', name: 'chamilo_core_course_home_json')]
    public function indexJson(
        Request $request,
        CShortcutRepository $shortcutRepository,
        EntityManagerInterface $em,
        AssetRepository $assetRepository
    ): Response {
        $requestData = json_decode($request->getContent(), true);
        // Sort behaviour
        if (!empty($requestData) && isset($requestData['toolItem'])) {
            $index = $requestData['index'];
            $toolItem = $requestData['toolItem'];
            $toolId = (int) $toolItem['iid'];

            /** @var CTool $cTool */
            $cTool = $em->find(CTool::class, $toolId);

            if ($cTool) {
                $cTool->setPosition($index + 1);
                $em->persist($cTool);
                $em->flush();
            }
        }

        $course = $this->getCourse();
        $sessionId = $this->getSessionId();
        $isInASession = $sessionId > 0;

        if (null === $course) {
            throw $this->createAccessDeniedException();
        }

        if (empty($sessionId)) {
            $this->denyAccessUnlessGranted(CourseVoter::VIEW, $course);
        }

        $sessionHandler = $request->getSession();

        $userId = 0;

        $user = $this->userHelper->getCurrent();
        if (null !== $user) {
            $userId = $user->getId();
        }

        $courseCode = $course->getCode();
        $courseId = $course->getId();

        if ($user && $user->hasRole('ROLE_INVITEE')) {
            $isSubscribed = CourseManager::is_user_subscribed_in_course(
                $userId,
                $courseCode,
                $isInASession,
                $sessionId
            );

            if (!$isSubscribed) {
                throw $this->createAccessDeniedException();
            }
        }

        $isSpecialCourse = CourseManager::isSpecialCourse($courseId);

        if ($user && $isSpecialCourse && (isset($_GET['autoreg']) && 1 === (int) $_GET['autoreg'])
            && CourseManager::subscribeUser($userId, $courseId, STUDENT)
        ) {
            $sessionHandler->set('is_allowed_in_course', true);
        }

        $logInfo = [
            'tool' => 'course-main',
        ];
        Event::registerLog($logInfo);

        // Deleting the objects
        $sessionHandler->remove('toolgroup');
        $sessionHandler->remove('_gid');
        $sessionHandler->remove('oLP');
        $sessionHandler->remove('lpobject');

        api_remove_in_gradebook();
        Exercise::cleanSessionVariables();

        $shortcuts = [];
        if (null !== $user) {
            $shortcutQuery = $shortcutRepository->getResources($course->getResourceNode());
            $shortcuts = $shortcutQuery->getQuery()->getResult();

            /** @var CShortcut $shortcut */
            foreach ($shortcuts as $shortcut) {
                $resourceNode = $shortcut->getShortCutNode();
                $cLink = $em->getRepository(CLink::class)->findOneBy(['resourceNode' => $resourceNode]);

                if ($cLink) {
                    $shortcut->setCustomImageUrl(
                        $cLink->getCustomImage()
                            ? $assetRepository->getAssetUrl($cLink->getCustomImage())
                            : null
                    );
                } else {
                    $shortcut->setCustomImageUrl(null);
                }
            }
        }
        $responseData = [
            'shortcuts' => $shortcuts,
            'diagram' => '',
        ];

        $json = $this->serializer->serialize(
            $responseData,
            'json',
            [
                'groups' => ['course:read', 'ctool:read', 'tool:read', 'cshortcut:read'],
            ]
        );

        return new Response(
            $json,
            Response::HTTP_OK,
            [
                'Content-type' => 'application/json',
            ]
        );
    }
    #[Route('/{courseId}/next-course', name: 'chamilo_course_next_course')]
    public function getNextCourse(
        int $courseId,
        Request $request,
        SequenceResourceRepository $repo,
        Security $security,
        SettingsManager $settingsManager,
        EntityManagerInterface $em
    ): JsonResponse {
        $sessionId = $request->query->getInt('sid');
        $useDependents = $request->query->getBoolean('dependents', false);
        $user = $security->getUser();
        $userId = $user->getId();

        if ($useDependents) {
            $sequences = $repo->getDependents($courseId, SequenceResource::COURSE_TYPE);
            $checked = $repo->checkDependentsForUser($sequences, SequenceResource::COURSE_TYPE, $userId, $sessionId);
            $isUnlocked = $repo->checkSequenceAreCompleted($checked);
            $sequenceResource = $repo->findRequirementForResource($courseId, SequenceResource::COURSE_TYPE);
        } else {
            $sequences = $repo->getRequirements($courseId, SequenceResource::COURSE_TYPE);

            $hasValidRequirement = false;
            foreach ($sequences as $sequence) {
                foreach ($sequence['requirements'] ?? [] as $resource) {
                    if ($resource instanceof Course) {
                        $hasValidRequirement = true;

                        break 2;
                    }
                }
            }

            if (!$hasValidRequirement) {
                return new JsonResponse([]);
            }

            $checked = $repo->checkRequirementsForUser($sequences, SequenceResource::COURSE_TYPE, $userId, $sessionId);
            $isUnlocked = $repo->checkSequenceAreCompleted($checked);
            $sequenceResource = $repo->findRequirementForResource($courseId, SequenceResource::COURSE_TYPE);
        }

        $graphImage = null;

        if ($sequenceResource && $sequenceResource->hasGraph()) {
            $graph = $sequenceResource->getSequence()->getUnSerializeGraph();
            if (null !== $graph) {
                $graph->setAttribute('graphviz.node.fontname', 'arial');
                $graphviz = new GraphViz();
                $graphImage = $graphviz->createImageSrc($graph);
            }
        }

        return new JsonResponse([
            'sequenceList' => array_values($checked),
            'allowSubscription' => $isUnlocked,
            'graph' => $graphImage,
        ]);
    }

    /**
     * Redirects the page to a tool, following the tools settings.
     */
    #[Route('/{cid}/tool/{toolName}', name: 'chamilo_core_course_redirect_tool')]
    public function redirectTool(
        Request $request,
        string $toolName,
        CToolRepository $repo,
        ToolChain $toolChain
    ): RedirectResponse {
        /** @var CTool|null $tool */
        $tool = $repo->findOneBy([
            'title' => $toolName,
        ]);

        if (null === $tool) {
            throw new NotFoundHttpException($this->trans('Tool not found'));
        }

        $tool = $toolChain->getToolFromName($tool->getTool()->getTitle());
        $link = $tool->getLink();

        if (null === $this->getCourse()) {
            throw new NotFoundHttpException($this->trans('Course not found'));
        }
        $optionalParams = '';

        $optionalParams = $request->query->get('cert') ? '&cert='.$request->query->get('cert') : '';

        if (strpos($link, 'nodeId')) {
            $nodeId = (string) $this->getCourse()->getResourceNode()->getId();
            $link = str_replace(':nodeId', $nodeId, $link);
        }

        $url = $link.'?'.$this->getCourseUrlQuery().$optionalParams;

        return $this->redirect($url);
    }

    /*public function redirectToShortCut(string $toolName, CToolRepository $repo, ToolChain $toolChain): RedirectResponse
     * {
     * $tool = $repo->findOneBy([
     * 'name' => $toolName,
     * ]);
     * if (null === $tool) {
     * throw new NotFoundHttpException($this->trans('Tool not found'));
     * }
     * $tool = $toolChain->getToolFromName($tool->getTool()->getTitle());
     * $link = $tool->getLink();
     * if (strpos($link, 'nodeId')) {
     * $nodeId = (string) $this->getCourse()->getResourceNode()->getId();
     * $link = str_replace(':nodeId', $nodeId, $link);
     * }
     * $url = $link.'?'.$this->getCourseUrlQuery();
     * return $this->redirect($url);
     * }*/

    /**
     * Edit configuration with given namespace.
     */
    #[Route('/{course}/settings/{namespace}', name: 'chamilo_core_course_settings')]
    public function updateSettings(
        Request $request,
        #[MapEntity(expr: 'repository.find(cid)')]
        Course $course,
        string $namespace,
        SettingsCourseManager $manager,
        SettingsFormFactory $formFactory
    ): Response {
        $this->denyAccessUnlessGranted(CourseVoter::VIEW, $course);

        $schemaAlias = $manager->convertNameSpaceToService($namespace);
        $settings = $manager->load($namespace);

        $form = $formFactory->create($schemaAlias);

        $form->setData($settings);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $messageType = 'success';

            try {
                $manager->setCourse($course);
                $manager->save($form->getData());
                $message = $this->trans('Update');
            } catch (ValidatorException $validatorException) {
                $message = $this->trans($validatorException->getMessage());
                $messageType = 'error';
            }
            $this->addFlash($messageType, $message);

            if ($request->headers->has('referer')) {
                return $this->redirect($request->headers->get('referer'));
            }
        }

        $schemas = $manager->getSchemas();

        return $this->render(
            '@ChamiloCore/Course/settings.html.twig',
            [
                'course' => $course,
                'schemas' => $schemas,
                'settings' => $settings,
                'form' => $form,
            ]
        );
    }

    #[Route('/{id}/about', name: 'chamilo_core_course_about')]
    public function about(
        Course $course,
        IllustrationRepository $illustrationRepository,
        CCourseDescriptionRepository $courseDescriptionRepository,
        EntityManagerInterface $em,
        Request $request
    ): Response {
        $courseId = $course->getId();

        $user = $this->userHelper->getCurrent();

        $fieldsRepo = $em->getRepository(ExtraField::class);

        /** @var TagRepository $tagRepo */
        $tagRepo = $em->getRepository(Tag::class);

        $courseDescriptions = $courseDescriptionRepository->getResourcesByCourse($course)->getQuery()->getResult();

        $courseValues = new ExtraFieldValue('course');

        $urlCourse = api_get_path(WEB_PATH).\sprintf('course/%s/about', $courseId);
        $courseTeachers = $course->getTeachersSubscriptions();
        $teachersData = [];

        foreach ($courseTeachers as $teacherSubscription) {
            $teacher = $teacherSubscription->getUser();
            $userData = [
                'complete_name' => UserManager::formatUserFullName($teacher),
                'image' => $illustrationRepository->getIllustrationUrl($teacher),
                'diploma' => $teacher->getDiplomas(),
                'openarea' => $teacher->getOpenarea(),
            ];

            $teachersData[] = $userData;
        }

        /** @var ExtraField $tagField */
        $tagField = $fieldsRepo->findOneBy([
            'itemType' => ExtraField::COURSE_FIELD_TYPE,
            'variable' => 'tags',
        ]);

        $courseTags = [];
        if (null !== $tagField) {
            $courseTags = $tagRepo->getTagsByItem($tagField, $courseId);
        }

        $courseDescription = $courseObjectives = $courseTopics = $courseMethodology = '';
        $courseMaterial = $courseResources = $courseAssessment = '';
        $courseCustom = [];
        foreach ($courseDescriptions as $descriptionTool) {
            switch ($descriptionTool->getDescriptionType()) {
                case CCourseDescription::TYPE_DESCRIPTION:
                    $courseDescription = $descriptionTool->getContent();

                    break;

                case CCourseDescription::TYPE_OBJECTIVES:
                    $courseObjectives = $descriptionTool;

                    break;

                case CCourseDescription::TYPE_TOPICS:
                    $courseTopics = $descriptionTool;

                    break;

                case CCourseDescription::TYPE_METHODOLOGY:
                    $courseMethodology = $descriptionTool;

                    break;

                case CCourseDescription::TYPE_COURSE_MATERIAL:
                    $courseMaterial = $descriptionTool;

                    break;

                case CCourseDescription::TYPE_RESOURCES:
                    $courseResources = $descriptionTool;

                    break;

                case CCourseDescription::TYPE_ASSESSMENT:
                    $courseAssessment = $descriptionTool;

                    break;

                case CCourseDescription::TYPE_CUSTOM:
                    $courseCustom[] = $descriptionTool;

                    break;
            }
        }

        $topics = [
            'objectives' => $courseObjectives,
            'topics' => $courseTopics,
            'methodology' => $courseMethodology,
            'material' => $courseMaterial,
            'resources' => $courseResources,
            'assessment' => $courseAssessment,
            'custom' => array_reverse($courseCustom),
        ];

        $subscriptionUser = false;

        if ($user) {
            $subscriptionUser = CourseManager::is_user_subscribed_in_course($user->getId(), $course->getCode());
        }

        $allowSubscribe = CourseManager::canUserSubscribeToCourse($course->getCode());

        $image = Container::getIllustrationRepository()->getIllustrationUrl($course, 'course_picture_medium');

        $params = [
            'course' => $course,
            'description' => $courseDescription,
            'image' => $image,
            'syllabus' => $topics,
            'tags' => $courseTags,
            'teachers' => $teachersData,
            'extra_fields' => $courseValues->getAllValuesForAnItem(
                $course->getId(),
                null,
                true
            ),
            'subscription' => $subscriptionUser,
            'url' => '',
            'is_premium' => '',
            'token' => '',
            'base_url' => $request->getSchemeAndHttpHost(),
            'allow_subscribe' => $allowSubscribe,
        ];

        $metaInfo = '<meta property="og:url" content="'.$urlCourse.'" />';
        $metaInfo .= '<meta property="og:type" content="website" />';
        $metaInfo .= '<meta property="og:title" content="'.$course->getTitle().'" />';
        $metaInfo .= '<meta property="og:description" content="'.strip_tags($courseDescription).'" />';
        $metaInfo .= '<meta property="og:image" content="'.$image.'" />';

        $htmlHeadXtra[] = $metaInfo;
        $htmlHeadXtra[] = api_get_asset('readmore-js/readmore.js');

        return $this->render('@ChamiloCore/Course/about.html.twig', $params);
    }

    #[Route('/{id}/welcome', name: 'chamilo_core_course_welcome')]
    public function welcome(Course $course): Response
    {
        return $this->render('@ChamiloCore/Course/welcome.html.twig', [
            'course' => $course,
        ]);
    }

    private function findIntroOfCourse(Course $course): ?CTool
    {
        $qb = $this->em->createQueryBuilder();

        $query = $qb->select('ct')
            ->from(CTool::class, 'ct')
            ->where('ct.course = :c_id')
            ->andWhere('ct.title = :title')
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->eq('ct.session', ':session_id'),
                    $qb->expr()->isNull('ct.session')
                )
            )
            ->setParameters([
                'c_id' => $course->getId(),
                'title' => 'course_homepage',
                'session_id' => 0,
            ])
            ->getQuery()
        ;

        $results = $query->getResult();

        return \count($results) > 0 ? $results[0] : null;
    }

    #[Route('/{id}/getToolIntro', name: 'chamilo_core_course_gettoolintro')]
    public function getToolIntro(Request $request, Course $course, EntityManagerInterface $em): Response
    {
        $sessionId = (int) $request->get('sid');

        // $session = $this->getSession();
        $responseData = [];
        $ctoolRepo = $em->getRepository(CTool::class);
        $sessionRepo = $em->getRepository(Session::class);
        $createInSession = false;

        $session = null;

        if (!empty($sessionId)) {
            $session = $sessionRepo->find($sessionId);
        }

        $ctool = $this->findIntroOfCourse($course);

        if ($session) {
            $ctoolSession = $ctoolRepo->findOneBy(['title' => 'course_homepage', 'course' => $course, 'session' => $session]);

            if (!$ctoolSession) {
                $createInSession = true;
            } else {
                $ctool = $ctoolSession;
            }
        }

        if ($ctool) {
            $ctoolintroRepo = $em->getRepository(CToolIntro::class);

            /** @var CToolIntro $ctoolintro */
            $ctoolintro = $ctoolintroRepo->findOneBy(['courseTool' => $ctool]);
            if ($ctoolintro) {
                $responseData = [
                    'iid' => $ctoolintro->getIid(),
                    'introText' => $ctoolintro->getIntroText(),
                    'createInSession' => $createInSession,
                    'cToolId' => $ctool->getIid(),
                ];
            }
            $responseData['c_tool'] = [
                'iid' => $ctool->getIid(),
                'title' => $ctool->getTitle(),
            ];
        }

        return new JsonResponse($responseData);
    }

    #[Route('/{id}/addToolIntro', name: 'chamilo_core_course_addtoolintro')]
    public function addToolIntro(Request $request, Course $course, EntityManagerInterface $em): Response
    {
        $data = json_decode($request->getContent());
        $sessionId = $data->sid ?? ($data->resourceLinkList[0]->sid ?? 0);
        $introText = $data->introText ?? null;

        $session = $sessionId ? $em->getRepository(Session::class)->find($sessionId) : null;
        $ctoolRepo = $em->getRepository(CTool::class);
        $ctoolintroRepo = $em->getRepository(CToolIntro::class);

        $ctoolSession = $ctoolRepo->findOneBy([
            'title' => 'course_homepage',
            'course' => $course,
            'session' => $session,
        ]);

        if (!$ctoolSession) {
            $toolEntity = $em->getRepository(Tool::class)->findOneBy(['title' => 'course_homepage']);
            if ($toolEntity) {
                $ctoolSession = (new CTool())
                    ->setTool($toolEntity)
                    ->setTitle('course_homepage')
                    ->setCourse($course)
                    ->setPosition(1)
                    ->setVisibility(true)
                    ->setParent($course)
                    ->setCreator($course->getCreator())
                    ->setSession($session)
                    ->addCourseLink($course)
                ;

                $em->persist($ctoolSession);
                $em->flush();
            }
        }

        $ctoolIntro = $ctoolintroRepo->findOneBy(['courseTool' => $ctoolSession]);
        if (!$ctoolIntro) {
            $ctoolIntro = (new CToolIntro())
                ->setCourseTool($ctoolSession)
                ->setIntroText($introText ?? '')
                ->setParent($course)
            ;

            $em->persist($ctoolIntro);
            $em->flush();

            return new JsonResponse([
                'status' => 'created',
                'cToolId' => $ctoolSession->getIid(),
                'introIid' => $ctoolIntro->getIid(),
                'introText' => $ctoolIntro->getIntroText(),
            ]);
        }

        if (null !== $introText) {
            $ctoolIntro->setIntroText($introText);
            $em->persist($ctoolIntro);
            $em->flush();

            return new JsonResponse([
                'status' => 'updated',
                'cToolId' => $ctoolSession->getIid(),
                'introIid' => $ctoolIntro->getIid(),
                'introText' => $ctoolIntro->getIntroText(),
            ]);
        }

        return new JsonResponse(['status' => 'no_action']);
    }

    #[Route('/check-enrollments', name: 'chamilo_core_check_enrollments', methods: ['GET'])]
    public function checkEnrollments(EntityManagerInterface $em, SettingsManager $settingsManager): JsonResponse
    {
        $user = $this->userHelper->getCurrent();

        if (!$user) {
            return new JsonResponse(['error' => 'User not found'], Response::HTTP_UNAUTHORIZED);
        }

        $isEnrolledInCourses = $this->isUserEnrolledInAnyCourse($user, $em);
        $isEnrolledInSessions = $this->isUserEnrolledInAnySession($user, $em);

        if (!$isEnrolledInCourses && !$isEnrolledInSessions) {
            $defaultMenuEntry = $settingsManager->getSetting('platform.default_menu_entry_for_course_or_session');
            $isEnrolledInCourses = 'my_courses' === $defaultMenuEntry;
            $isEnrolledInSessions = 'my_sessions' === $defaultMenuEntry;
        }

        return new JsonResponse([
            'isEnrolledInCourses' => $isEnrolledInCourses,
            'isEnrolledInSessions' => $isEnrolledInSessions,
        ]);
    }

    #[Route('/categories', name: 'chamilo_core_course_form_lists')]
    public function getCategories(
        SettingsManager $settingsManager,
        AccessUrlHelper $accessUrlHelper,
        CourseCategoryRepository $courseCategoriesRepo
    ): JsonResponse {
        $allowBaseCourseCategory = 'true' === $settingsManager->getSetting('course.allow_base_course_category');
        $accessUrlId = $accessUrlHelper->getCurrent()->getId();

        $categories = $courseCategoriesRepo->findAllInAccessUrl(
            $accessUrlId,
            $allowBaseCourseCategory
        );

        $data = [];
        $categoryToAvoid = '';
        if (!$this->isGranted('ROLE_ADMIN')) {
            $categoryToAvoid = $settingsManager->getSetting('course.course_category_code_to_use_as_model');
        }

        foreach ($categories as $category) {
            $categoryCode = $category->getCode();
            if (!empty($categoryToAvoid) && $categoryToAvoid == $categoryCode) {
                continue;
            }
            $data[] = ['id' => $category->getId(), 'name' => $category->__toString()];
        }

        return new JsonResponse($data);
    }

    #[Route('/search_templates', name: 'chamilo_core_course_search_templates')]
    public function searchCourseTemplates(
        Request $request,
        AccessUrlHelper $accessUrlUtil,
        CourseRepository $courseRepository
    ): JsonResponse {
        $searchTerm = $request->query->get('search', '');
        $accessUrl = $accessUrlUtil->getCurrent();

        $user = $this->userHelper->getCurrent();

        $courseList = $courseRepository->getCoursesInfoByUser($user, $accessUrl, 1, $searchTerm);
        $results = ['items' => []];
        foreach ($courseList as $course) {
            $title = $course['title'];
            $results['items'][] = [
                'id' => $course['id'],
                'name' => $title.' ('.$course['code'].') ',
            ];
        }

        return new JsonResponse($results);
    }

    #[Route('/create', name: 'chamilo_core_course_create')]
    public function createCourse(
        Request $request,
        TranslatorInterface $translator,
        CourseHelper $courseHelper
    ): JsonResponse {
        $courseData = json_decode($request->getContent(), true);

        $title = $courseData['name'] ?? null;
        $wantedCode = $courseData['code'] ?? null;
        $courseLanguage = $courseData['language'] ?? null;
        $categoryCode = $courseData['category'] ?? null;
        $exemplaryContent = $courseData['fillDemoContent'] ?? false;
        $template = $courseData['template'] ?? '';

        $params = [
            'title' => $title,
            'wanted_code' => $wantedCode,
            'course_language' => $courseLanguage,
            'exemplary_content' => $exemplaryContent,
            'course_template' => $template,
        ];

        if ($categoryCode) {
            $params['course_categories'] = $categoryCode;
        }

        try {
            $course = $courseHelper->createCourse($params);
            if ($course) {
                return new JsonResponse([
                    'success' => true,
                    'message' => $translator->trans('Course created successfully.'),
                    'courseId' => $course->getId(),
                ]);
            }
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $translator->trans($e->getMessage()),
            ], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['success' => false, 'message' => $translator->trans('An error occurred while creating the course.')]);
    }

    #[Route('/{id}/getAutoLaunchExerciseId', name: 'chamilo_core_course_get_auto_launch_exercise_id', methods: ['GET'])]
    public function getAutoLaunchExerciseId(
        Request $request,
        Course $course,
        CQuizRepository $quizRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $data = $request->getContent();
        $data = json_decode($data);
        $sessionId = $data->sid ?? 0;

        $sessionRepo = $em->getRepository(Session::class);
        $session = null;
        if (!empty($sessionId)) {
            $session = $sessionRepo->find($sessionId);
        }

        $autoLaunchExerciseId = $quizRepository->findAutoLaunchableQuizByCourseAndSession($course, $session);

        return new JsonResponse(['exerciseId' => $autoLaunchExerciseId], Response::HTTP_OK);
    }

    #[Route('/{id}/getAutoLaunchLPId', name: 'chamilo_core_course_get_auto_launch_lp_id', methods: ['GET'])]
    public function getAutoLaunchLPId(
        Request $request,
        Course $course,
        CLpRepository $lpRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $data = $request->getContent();
        $data = json_decode($data);
        $sessionId = $data->sid ?? 0;

        $sessionRepo = $em->getRepository(Session::class);
        $session = null;
        if (!empty($sessionId)) {
            $session = $sessionRepo->find($sessionId);
        }

        $autoLaunchLPId = $lpRepository->findAutoLaunchableLPByCourseAndSession($course, $session);

        return new JsonResponse(['lpId' => $autoLaunchLPId], Response::HTTP_OK);
    }

    private function autoLaunch(): void
    {
        $autoLaunchWarning = '';
        $showAutoLaunchLpWarning = false;
        $course_id = api_get_course_int_id();
        $lpAutoLaunch = api_get_course_setting('enable_lp_auto_launch');
        $session_id = api_get_session_id();
        $allowAutoLaunchForCourseAdmins =
            api_is_platform_admin()
            || api_is_allowed_to_edit(true, true)
            || api_is_coach();

        if (!empty($lpAutoLaunch)) {
            if (2 === $lpAutoLaunch) {
                // LP list
                if ($allowAutoLaunchForCourseAdmins) {
                    $showAutoLaunchLpWarning = true;
                } else {
                    $session_key = 'lp_autolaunch_'.$session_id.'_'.$course_id.'_'.api_get_user_id();
                    if (!isset($_SESSION[$session_key])) {
                        // Redirecting to the LP
                        $url = api_get_path(WEB_CODE_PATH).'lp/lp_controller.php?'.api_get_cidreq();
                        $_SESSION[$session_key] = true;
                        header(\sprintf('Location: %s', $url));

                        exit;
                    }
                }
            } else {
                $lp_table = Database::get_course_table(TABLE_LP_MAIN);
                $condition = '';
                if (!empty($session_id)) {
                    $condition = api_get_session_condition($session_id);
                    $sql = "SELECT id FROM {$lp_table}
                            WHERE c_id = {$course_id} AND autolaunch = 1 {$condition}
                            LIMIT 1";
                    $result = Database::query($sql);
                    // If we found nothing in the session we just called the session_id =  0 autolaunch
                    if (0 === Database::num_rows($result)) {
                        $condition = '';
                    }
                }

                $sql = "SELECT iid FROM {$lp_table}
                        WHERE c_id = {$course_id} AND autolaunch = 1 {$condition}
                        LIMIT 1";
                $result = Database::query($sql);
                if (Database::num_rows($result) > 0) {
                    $lp_data = Database::fetch_array($result);
                    if (!empty($lp_data['iid'])) {
                        if ($allowAutoLaunchForCourseAdmins) {
                            $showAutoLaunchLpWarning = true;
                        } else {
                            $session_key = 'lp_autolaunch_'.$session_id.'_'.api_get_course_int_id().'_'.api_get_user_id();
                            if (!isset($_SESSION[$session_key])) {
                                // Redirecting to the LP
                                $url = api_get_path(WEB_CODE_PATH).
                                    'lp/lp_controller.php?'.api_get_cidreq().'&action=view&lp_id='.$lp_data['iid'];

                                $_SESSION[$session_key] = true;
                                header(\sprintf('Location: %s', $url));

                                exit;
                            }
                        }
                    }
                }
            }
        }

        if ($showAutoLaunchLpWarning) {
            $autoLaunchWarning = get_lang(
                'The learning path auto-launch setting is ON. When learners enter this course, they will be automatically redirected to the learning path marked as auto-launch.'
            );
        }

        $forumAutoLaunch = (int) api_get_course_setting('enable_forum_auto_launch');
        if (1 === $forumAutoLaunch) {
            if ($allowAutoLaunchForCourseAdmins) {
                if (empty($autoLaunchWarning)) {
                    $autoLaunchWarning = get_lang(
                        "The forum's auto-launch setting is on. Students will be redirected to the forum tool when entering this course."
                    );
                }
            } else {
                $url = api_get_path(WEB_CODE_PATH).'forum/index.php?'.api_get_cidreq();
                header(\sprintf('Location: %s', $url));

                exit;
            }
        }

        $exerciseAutoLaunch = (int) api_get_course_setting('enable_exercise_auto_launch');
        if (2 === $exerciseAutoLaunch) {
            if ($allowAutoLaunchForCourseAdmins) {
                if (empty($autoLaunchWarning)) {
                    $autoLaunchWarning = get_lang(
                        'TheExerciseAutoLaunchSettingIsONStudentsWillBeRedirectToTheExerciseList'
                    );
                }
            } else {
                // Redirecting to the document
                $url = api_get_path(WEB_CODE_PATH).'exercise/exercise.php?'.api_get_cidreq();
                header(\sprintf('Location: %s', $url));

                exit;
            }
        } elseif (1 === $exerciseAutoLaunch) {
            if ($allowAutoLaunchForCourseAdmins) {
                if (empty($autoLaunchWarning)) {
                    $autoLaunchWarning = get_lang(
                        'TheExerciseAutoLaunchSettingIsONStudentsWillBeRedirectToAnSpecificExercise'
                    );
                }
            } else {
                // Redirecting to an exercise
                $table = Database::get_course_table(TABLE_QUIZ_TEST);
                $condition = '';
                if (!empty($session_id)) {
                    $condition = api_get_session_condition($session_id);
                    $sql = "SELECT iid FROM {$table}
                            WHERE c_id = {$course_id} AND autolaunch = 1 {$condition}
                            LIMIT 1";
                    $result = Database::query($sql);
                    // If we found nothing in the session we just called the session_id = 0 autolaunch
                    if (0 === Database::num_rows($result)) {
                        $condition = '';
                    }
                }

                $sql = "SELECT iid FROM {$table}
                        WHERE c_id = {$course_id} AND autolaunch = 1 {$condition}
                        LIMIT 1";
                $result = Database::query($sql);
                if (Database::num_rows($result) > 0) {
                    $row = Database::fetch_array($result);
                    $exerciseId = $row['iid'];
                    $url = api_get_path(WEB_CODE_PATH).
                        'exercise/overview.php?exerciseId='.$exerciseId.'&'.api_get_cidreq();
                    header(\sprintf('Location: %s', $url));

                    exit;
                }
            }
        }

        $documentAutoLaunch = (int) api_get_course_setting('enable_document_auto_launch');
        if (1 === $documentAutoLaunch) {
            if ($allowAutoLaunchForCourseAdmins) {
                if (empty($autoLaunchWarning)) {
                    $autoLaunchWarning = get_lang(
                        'The document auto-launch feature configuration is enabled. Learners will be automatically redirected to document tool.'
                    );
                }
            } else {
                // Redirecting to the document
                $url = api_get_path(WEB_CODE_PATH).'document/document.php?'.api_get_cidreq();
                header("Location: $url");

                exit;
            }
        }

        /*  SWITCH TO A DIFFERENT HOMEPAGE VIEW
         the setting homepage_view is adjustable through
         the platform administration section */
        if (!empty($autoLaunchWarning)) {
            $this->addFlash(
                'warning',
                Display::return_message(
                    $autoLaunchWarning,
                    'warning'
                )
            );
        }
    }

    // Implement the real logic to check course enrollment
    private function isUserEnrolledInAnyCourse(User $user, EntityManagerInterface $em): bool
    {
        $enrollmentCount = $em
            ->getRepository(CourseRelUser::class)
            ->count(['user' => $user])
        ;

        return $enrollmentCount > 0;
    }

    // Implement the real logic to check session enrollment
    private function isUserEnrolledInAnySession(User $user, EntityManagerInterface $em): bool
    {
        $enrollmentCount = $em->getRepository(SessionRelUser::class)
            ->count(['user' => $user])
        ;

        return $enrollmentCount > 0;
    }
}
