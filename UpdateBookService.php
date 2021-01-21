<?php

namespace AppBundle\Util;


use AppBundle\Entity\Book\Answer;
use AppBundle\Entity\Book\Book;
use AppBundle\Entity\Book\Content\AbstractContent;
use AppBundle\Entity\Book\Content\ExerciseContent;
use AppBundle\Entity\Book\Content\HeaderContent;
use AppBundle\Entity\Book\Content\ListContent;
use AppBundle\Entity\Book\Content\MultimediaContent;
use AppBundle\Entity\Book\Content\TextContent;
use AppBundle\Entity\Book\Exercise\AbstractExercise;
use AppBundle\Entity\Book\Exercise\CategoryExercise\CategoryExercise;
use AppBundle\Entity\Book\Exercise\CategoryExercise\WordCategory;
use AppBundle\Entity\Book\Exercise\CoinsExercise;
use AppBundle\Entity\Book\Exercise\GapExercise;
use AppBundle\Entity\Book\Exercise\InputExercise;
use AppBundle\Entity\Book\Exercise\OpenQuestionExercise;
use AppBundle\Entity\Book\Exercise\OpenQuestionWithAnswerExercise;
use AppBundle\Entity\Book\Exercise\TableExercise;
use AppBundle\Entity\Book\Page;
use AppBundle\Entity\Book\ReplyOption\ReplyOption;
use AppBundle\Entity\Book\WordDefinition;
use AppBundle\Entity\Language;
use AppBundle\Entity\Media\Audio;
use AppBundle\Entity\Media\Image;
use AppBundle\Entity\Media\Property;
use AppBundle\Entity\Media\Video;
use AppBundle\Entity\Profile\User;
use Doctrine\Common\Persistence\ObjectManager;
use Enqueue\Client\ProducerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

class UpdateBookService
{

    /** @var $user User */
    private $user;
    /** @var $om ObjectManager */
    private $om;
    /** @var $book Book */
    private $book;
    /** @var $replaceArray array */
    private $replaceArray = ['avatar', 'title', 'isCompleted', 'author', 'intro', 'isArabic', 'productId', 'pagesPreview', 'isFree', 'isPrinted', 'allowSpeechSynthesis', 'canEdit', 'language', 'isIndent', 'status', 'demoStatus', 'isScrollPage', 'templateType', 'speechSyntezStudentAnswer'];
    /** @var $deleteExercises array */
    private $deleteExercises;
    /** @var $deleteCategories array */
    private $deleteCategories;
    /** @var $addExercise array */
    private $addExercises;
    /** @var $deleteHeaders array */
    private $deleteHeaders;
    /** @var $deleteHeaders array */
    private $deleteList;
    /** @var $deleteGapAnswer array */
    private $deleteGapAnswer;
    /** @var $addHeaders array */
    private $addHeaders;
    /** @var $addLists array */
    private $addLists;
    /** @var $deleteMultimedia array */
    private $deleteMultimedia;
    /** @var $addMultimedia array */
    private $addMultimedia;
    /** @var $deleteText array */
    private $deleteText;
    /** @var $addText array */
    private $addText;
    /** @var $arrayDeletePage array */
    private $arrayDeletePage;
    /**
     * @var SpeechSyntezatorService
     */
    private $speechSyntezatorService;

    private $reSpeechContentArray = [];
    /**
     * @var ProducerInterface
     */
    private $producer;
    /**
     * @var IndentService
     */
    private $indentService;


    /**
     * ChangeAvatarService constructor.
     * @param ObjectManager $om
     * @param TokenStorage $tokenStorage
     * @param SpeechSyntezatorService $speechSyntezatorService
     * @param ProducerInterface $producer
     * @param IndentService $indentService
     */
    public function __construct(ObjectManager $om, TokenStorage $tokenStorage, SpeechSyntezatorService $speechSyntezatorService, ProducerInterface $producer, IndentService $indentService)
    {
        $this->om = $om;
        $this->user = !empty($tokenStorage->getToken()) ? $tokenStorage->getToken()->getUser() : null;
        $this->speechSyntezatorService = $speechSyntezatorService;
        $this->producer = $producer;
        $this->indentService = $indentService;
    }

    /**
     * @param Book $book
     * @param array $newVersionBook
     * @return Book
     */
    public function updateBook(Book $book, array $newVersionBook)
    {
        $this->book = $book;
        $this->wordsDefinitions($newVersionBook);
        $this->replaceBookEntityData($newVersionBook);
        $this->pageContent($newVersionBook);

        $deleteExercises = array_diff_key((array)$this->deleteExercises, (array)$this->addExercises);
        $deleteHeaders = array_diff_key((array)$this->deleteHeaders, (array)$this->addHeaders);
        $deleteList = array_diff_key((array)$this->deleteList, (array)$this->addLists);
        $deleteMultimedias = array_diff_key((array)$this->deleteMultimedia, (array)$this->addMultimedia);
        $deleteTexts = array_diff_key((array)$this->deleteText, (array)$this->addText);
        $arrayForDelete = $deleteExercises + $deleteHeaders + $deleteMultimedias + $deleteTexts + $deleteList;

        if (is_array($arrayForDelete) and !empty($arrayForDelete)) {
            $this->removeContent($arrayForDelete);
        }

        $this->saveStep();

        if (is_array($this->arrayDeletePage) and !empty($this->arrayDeletePage)) {
            /** @var Page $value */
            foreach ($this->arrayDeletePage as $value) {
                if ($value instanceof Page and $value->getBook()->getId() == $this->book->getId()) {
                    $this->book->removePage($value);
                    $this->om->remove($value);
                }
            }
        }
        $this->saveStep();

        if (!empty($this->reSpeechContentArray)) {
            $this->reSpeechContent($this->reSpeechContentArray);
        }

        return $this->book;
    }

    /**
     * @param array $newVersionBook
     */
    private function pageContent(array $newVersionBook)
    {
        $createNewPageArray = [];
        if (!$this->book->getPages()->isEmpty()) {
            foreach ($this->book->getPages() as $page) {
                $deletePage = true;
                if (array_key_exists('pages', $newVersionBook) and is_array($newVersionBook['pages']) and !empty($newVersionBook['pages'])) {
                    foreach ($newVersionBook['pages'] as $keyNewPage => $newPage) {
                        if (is_array($newPage) and array_key_exists('id', $newPage) and (int)$newPage['id'] == (int)$page->getId()) {
                            $deletePage = false;
                            $this->updatePage($page, $newPage);
                        } elseif (!array_key_exists('id', $newPage)) {
                            $createNewPageArray[$keyNewPage] = $newPage;
                        }
                    }
                    if ($deletePage) {
                        $this->arrayDeletePage[$page->getId()] = $page;
                    }
                } else {
                    $this->arrayDeletePage[$page->getId()] = $page;
                }
            }
            if (is_array($createNewPageArray) and !empty($createNewPageArray)) {
                $this->addPage($createNewPageArray);
            }
        } elseif (array_key_exists('pages', $newVersionBook) and is_array($newVersionBook['pages']) and !empty($newVersionBook['pages'])) {
            $this->addPage($newVersionBook['pages']);
        }
    }

    /**
     * @param array $createNewPageArray
     */
    private function addPage(array $createNewPageArray)
    {
        foreach ($createNewPageArray as $page) {
            $newPage = $this->createPage($page);

            if (array_key_exists('exercises', $page) and !empty($page['exercises'])) {
                $this->addExercises($page['exercises'], $newPage);
            }
            if (array_key_exists('headers', $page)) {
                $this->addHeaderContent($newPage, $page['headers']);
            }
            if (array_key_exists('list', $page)) {
                $this->addListContent($newPage, $page['list']);
            }
            if (array_key_exists('multimedias', $page)) {
                $this->addMultimediaContent($newPage, $page['multimedias']);
            }
            if (array_key_exists('texts', $page)) {
                $this->addTextContent($newPage, $page['texts']);
            }

            $this->book->addPage($newPage);
        }
    }

    /**
     * @param array $page
     * @return Page
     */
    private function createPage(array $page)
    {
        $newPage = new Page();
        $newPage->setBook($this->book);
        if (array_key_exists('pageNumber', $page) and is_int((int)$page['pageNumber'])) {
            $newPage->setPageNumber($page['pageNumber']);
        }
        $this->om->persist($newPage);

        return $newPage;
    }

    /**
     * @param Page $page
     * @param array $newPage
     */
    private function updatePage(Page &$page, array $newPage)
    {
        if (array_key_exists('pageNumber', $newPage) and is_int((int)$newPage['pageNumber'])) {
            $page->setPageNumber($newPage['pageNumber']);
        }
        $this->exercisesContent($page, $newPage);
        $this->headerContent($page, $newPage);
        $this->ListContent($page, $newPage);
        $this->multimediaContent($page, $newPage);
        $this->textContent($page, $newPage);
    }

    /**
     * @param Page $oldPage
     * @param array $newPage
     */
    private function exercisesContent(Page &$oldPage, array $newPage)
    {
        $createNewExerciseArray = [];
        $newExerciseWithId = [];
        $alreadyUpdated = [];
        if (!$oldPage->getExercises()->isEmpty()) {
            foreach ($oldPage->getExercises() as $oldExercise) {
                $deleteExercise = true;
                if (array_key_exists('exercises', $newPage) and is_array($newPage['exercises']) and !empty($newPage['exercises'])) {
                    foreach ($newPage['exercises'] as $keyNewExercise => $newExercise) {
                        if (is_array($newExercise) and array_key_exists('id', $newExercise) and (int)$newExercise['id'] == (int)$oldExercise->getId()) {
                            $alreadyUpdated[$keyNewExercise] = $newExercise;
                            $deleteExercise = false;
                            $this->updateExercise($newExercise, $oldExercise);
                        } elseif (array_key_exists('id', $newExercise)) {
                            $newExerciseWithId[$keyNewExercise] = $newExercise;
                        } elseif (!array_key_exists('id', $newExercise)) {
                            $createNewExerciseArray[$keyNewExercise] = $newExercise;
                        }
                    }
                    if ($deleteExercise) {
                        $this->deleteExercises[$oldExercise->getId()] = $oldExercise;
                    }
                } else {
                    $this->deleteExercises[$oldExercise->getId()] = $oldExercise;
                }
            }

            if (is_array(array_diff_key($newExerciseWithId, $alreadyUpdated) + $createNewExerciseArray) and
                !empty(array_diff_key($newExerciseWithId, $alreadyUpdated) + $createNewExerciseArray)) {
                $this->addExercises((array_diff_key($newExerciseWithId, $alreadyUpdated) + $createNewExerciseArray), $oldPage);
            }
        } elseif (array_key_exists('exercises', $newPage) and is_array($newPage['exercises']) and !empty($newPage['exercises'])) {
            $this->addExercises($newPage['exercises'], $oldPage);
        }
    }

    /**
     * @param array $exercises
     * @param Page $newPage
     */
    private function addExercises(array $exercises, Page &$newPage)
    {
        foreach ($exercises as $exercise) {
            if (array_key_exists('id', $exercise)) {
                $issetExercise = $this->om->getRepository(ExerciseContent::class)->find((int)$exercise['id']);
                if ($issetExercise instanceof ExerciseContent) {
                    $issetExercise->getPage()->removeExercise($issetExercise);
                    $issetExercise->setPage($newPage);
                    $newPage->addExercise($issetExercise);
                    $this->updateExercise($exercise, $issetExercise);
                    $this->addExercises[$issetExercise->getId()] = $issetExercise;
                }
            } else {
                $this->createExercise($exercise, $newPage);
            }
        }
    }

    /**
     * @param array $exercise
     * @param Page $newPage
     */
    private function createExercise(array $exercise, Page &$newPage)
    {
        $typeExercise = null;
        $newExercise = null;
        $newExerciseContent = new ExerciseContent();
        if (\array_key_exists('inputExercise', $exercise) and empty($typeExercise)) {
            $typeExercise = 'inputExercise';
            $newExercise = new InputExercise();
            $newExerciseContent->setInputExercise($newExercise);
        }
        if (\array_key_exists('categoryExercise', $exercise) and empty($typeExercise)) {
            $typeExercise = 'categoryExercise';
            $newExercise = new CategoryExercise();
            $newExerciseContent->setCategoryExercise($newExercise);
        }
        if (\array_key_exists('coinsExercise', $exercise) and empty($typeExercise)) {
            $typeExercise = 'coinsExercise';
            $newExercise = new CoinsExercise();
            $newExerciseContent->setCoinsExercise($newExercise);
        }
        if (\array_key_exists('openQuestionExercise', $exercise) and empty($typeExercise)) {
            $typeExercise = 'openQuestionExercise';
            $newExercise = new OpenQuestionExercise();
            $newExerciseContent->setOpenQuestionExercise($newExercise);
        }
        if (\array_key_exists('openQuestionWithAnswerExercise', $exercise) and empty($typeExercise)) {
            $typeExercise = 'openQuestionWithAnswerExercise';
            $newExercise = new OpenQuestionWithAnswerExercise();
            $newExerciseContent->setOpenQuestionWithAnswerExercise($newExercise);
        }
        if (\array_key_exists('tableExercise', $exercise) and empty($typeExercise)) {
            $typeExercise = 'tableExercise';
            $newExercise = new TableExercise();
            $newExerciseContent->setTableExercise($newExercise);
        }
        if (\array_key_exists('gapExercise', $exercise) and empty($typeExercise)) {
            $typeExercise = 'gapExercise';
            $newExercise = new GapExercise();
            $newExerciseContent->setGapExercise($newExercise);

            if (\array_key_exists('gaps', $exercise['gapExercise']) and !empty($exercise['gapExercise']['gaps'])) {
                foreach ($exercise['gapExercise']['gaps'] as $gap) {
                    $this->createGapContent($gap, $newExercise);
                }
            }
        }

        if (!empty($typeExercise) and !empty($newExercise)) {
            if (array_key_exists('question', $exercise[$typeExercise])) {
                $newExercise->setQuestion(
                    $this->indentService->replaceTab($exercise[$typeExercise]['question'])
                );
            }
            if (array_key_exists('order', $exercise)) {
                $newExerciseContent->setOrder((int)$exercise['order']);
            }
            if (array_key_exists('isArabic', $exercise[$typeExercise]) and is_bool($exercise[$typeExercise]['isArabic'])) {
                $newExercise->setIsArabic((bool)$exercise[$typeExercise]['isArabic']);
            }
            if (array_key_exists('language', $exercise) and $exercise['language']) {
                $language = $this->om->getRepository(Language::class)->find($exercise['language']);
                if ($language instanceof Language) {
                    $newExerciseContent->setLanguage($language);
                }
            }
        }
        if (array_key_exists('audioTrack', $exercise) and !empty($exercise['audioTrack'])) {
            $audio = $this->om->getRepository(Audio::class)->find($exercise['audioTrack']);
            if ($audio instanceof Audio) {
                $newExerciseContent->setAudioTrack($audio);
            }
        }

        if (array_key_exists('questionImage', $exercise)) {
            $image = $this->om->getRepository(Image::class)->find((int)$exercise['questionImage']);
            if ($image instanceof Image) {
                $newExerciseContent->setQuestionImage($image);
            }
        }
        if (array_key_exists('questionVideo', $exercise)) {
            $video = $this->om->getRepository(Video::class)->find((int)$exercise['questionVideo']);
            if ($video instanceof Video) {
                $newExerciseContent->setQuestionVideo($video);
            }
        }
        if (array_key_exists('questionLinkVideo', $exercise)) {
            $newExerciseContent->setQuestionLinkVideo((string)$exercise['questionLinkVideo']);
        }

        if (array_key_exists('audioType', $exercise)) {
            $newExerciseContent->setAudioType($exercise['audioType']);
        }
        if ($newExercise instanceof AbstractExercise) {
            $this->om->persist($newExercise);
            if (array_key_exists('answers', $exercise[$typeExercise]) and !empty($exercise[$typeExercise]['answers'])) {
                foreach ($exercise[$typeExercise]['answers'] as $answer) {
                    $this->createAnswerContent($answer, $newExercise);
                }
            }
        }
        if ($typeExercise == 'categoryExercise' and array_key_exists('wordCategories', $exercise[$typeExercise]) and !empty($exercise[$typeExercise]['wordCategories'])) {
            foreach ($exercise[$typeExercise]['wordCategories'] as $newCategory) {
                $category = new WordCategory();
                if (array_key_exists('title', $newCategory)) {
                    $category->setTitle($newCategory['title']);
                }
                if (array_key_exists('words', $newCategory)) {
                    $category->setWords($newCategory['words']);
                }
                $category->setCategoryExercise($newExercise);

                $newExercise->addWordCategory($category);

                $this->om->persist($category);
            }
        }

        if (array_key_exists('replyOptions', $exercise[$typeExercise]) and !empty($exercise[$typeExercise]['replyOptions'])) {
            foreach ($exercise[$typeExercise]['replyOptions'] as $value) {
                $replyOption = $this->om->getRepository(ReplyOption::class)->find($value);
                if ($replyOption instanceof ReplyOption) {
                    $newExercise->addReplyOption($replyOption);
                }
            }
        }

        $newExerciseContent->setPage($newPage);
        $this->om->persist($newExerciseContent);
        $this->saveStep($newExerciseContent);
        if ($this->book->getallowSpeechSynthesis() and $newExerciseContent->getLanguage() instanceof Language) {
            $this->reSpeechContentArray[] = $newExerciseContent;
            if (array_key_exists('audioType', $exercise) and !empty($exercise['audioType'])) {
                $newExerciseContent->setAudioType($exercise['audioType']);
            } else {
                $newExerciseContent->setAudioType('Speech syntesis');
            }
        }
        $newPage->addExercise($newExerciseContent);
    }

    /**
     * @param array $exercise
     * @param ExerciseContent $issetExercise
     */
    private function updateExercise(array $exercise, ExerciseContent &$issetExercise)
    {
        $type = null;
        $reSpeech = false;
        if (array_key_exists('order', $exercise)) {
            $issetExercise->setOrder((int)$exercise['order']);
        }
        if (array_key_exists('audioTrack', $exercise) and !empty($exercise['audioTrack'])) {
            $audio = $this->om->getRepository(Audio::class)->find($exercise['audioTrack']);
            if ($audio instanceof Audio) {
                $issetExercise->setAudioTrack($audio);
            }
        }
        if (array_key_exists('language', $exercise) and $exercise['language']) {
            $language = $this->om->getRepository(Language::class)->find($exercise['language']);
            if ($language instanceof Language) {
                if ($issetExercise->getLanguage() !== $language) {
                    $reSpeech = true;
                }

                $issetExercise->setLanguage($language);
            }
        }

        if (array_key_exists('questionImage', $exercise)) {
            if (!is_null($exercise['questionImage'])) {
                $image = $this->om->getRepository(Image::class)->find($exercise['questionImage']);
            }
            $issetExercise->setQuestionImage($image ?? $exercise['questionImage']);
        }
        if (array_key_exists('questionVideo', $exercise)) {
            if (!is_null($exercise['questionVideo'])) {
                $video = $this->om->getRepository(Video::class)->find($exercise['questionVideo']);
            }
            $issetExercise->setQuestionVideo($video ?? $exercise['questionVideo']);
        }
        if (array_key_exists('questionLinkVideo', $exercise)) {
            $issetExercise->setQuestionLinkVideo($exercise['questionLinkVideo']);
        }

        if (array_key_exists('reSpeech', $exercise) and $exercise['reSpeech']) {
            $reSpeech = true;
        }
        if (array_key_exists('audioType', $exercise)) {
            $issetExercise->setAudioType($exercise['audioType']);
        }
        if ($issetExercise->getInputExercise() instanceof InputExercise) {
            $type = 'inputExercise';
            $getExercise = $issetExercise->getInputExercise();
        } elseif ($issetExercise->getCoinsExercise() instanceof CoinsExercise) {
            $type = 'coinsExercise';
            $getExercise = $issetExercise->getCoinsExercise();
        } elseif ($issetExercise->getGapExercise() instanceof GapExercise) {
            $type = 'gapExercise';
            $getExercise = $issetExercise->getGapExercise();
            $this->gapContent($getExercise, $exercise['gapExercise']);
        } elseif ($issetExercise->getOpenQuestionExercise() instanceof OpenQuestionExercise) {
            $type = 'openQuestionExercise';
            $getExercise = $issetExercise->getOpenQuestionExercise();
        } elseif ($issetExercise->getOpenQuestionWithAnswerExercise() instanceof OpenQuestionWithAnswerExercise) {
            $type = 'openQuestionWithAnswerExercise';
            $getExercise = $issetExercise->getOpenQuestionWithAnswerExercise();
        } elseif ($issetExercise->getTableExercise() instanceof TableExercise) {
            $type = 'tableExercise';
            $getExercise = $issetExercise->getTableExercise();
        } elseif ($issetExercise->getCategoryExercise() instanceof CategoryExercise) {
            $type = 'categoryExercise';
            $getExercise = $issetExercise->getCategoryExercise();
        }

        if (array_key_exists($type, $exercise)) {
            if (array_key_exists('question', $exercise[$type])) {
                if ($getExercise->getQuestion() !== $exercise[$type]['question']) {
                    $reSpeech = true;
                }

                $getExercise->setQuestion(
                    $this->indentService->replaceTab($exercise[$type]['question'])
                );
            }
            if (array_key_exists('isArabic', $exercise[$type]) and is_bool($exercise[$type]['isArabic'])) {
                $getExercise->setIsArabic((bool)$exercise[$type]['isArabic']);
            }
            if (!$getExercise->getReplyOptions()->isEmpty()) {
                foreach ($getExercise->getReplyOptions() as $option) {
                    $getExercise->removeReplyOption($option);
                }
            }
            if (array_key_exists('replyOptions', $exercise[$type]) and is_array($exercise[$type]['replyOptions']) and !empty($exercise[$type]['replyOptions'])) {
                foreach ($exercise[$type]['replyOptions'] as $value) {
                    $replyOption = $this->om->getRepository(ReplyOption::class)->find($value);
                    if ($replyOption instanceof ReplyOption) {
                        $getExercise->addReplyOption($replyOption);
                    }
                }
            }

            $this->answerContent($getExercise, $exercise[$type]);

            if ($getExercise instanceof CategoryExercise and !$getExercise->getWordCategories()->isEmpty()) {
                $newExerciseWithIdCategory = [];
                $alreadyUpdatedCategory = [];
                $createWordCategory = [];
                /** @var WordCategory $category */
                foreach ($getExercise->getWordCategories() as $category) {
                    $deleteExercise = true;
                    if (array_key_exists('wordCategories', $exercise[$type]) and !empty($exercise[$type]['wordCategories'])) {
                        foreach ($exercise[$type]['wordCategories'] as $keyNewCategory => $newCategory) {
                            if (array_key_exists('id', $newCategory) and $category->getId() == $newCategory['id']) {
                                $alreadyUpdatedCategory[$keyNewCategory] = $newCategory;
                                $deleteExercise = false;
                                $this->updateCategoryExercise($category, $newCategory);
                            } elseif (array_key_exists('id', $newCategory)) {
                                $newExerciseWithIdCategory[$keyNewCategory] = $newCategory;
                            } elseif (!array_key_exists('id', $newCategory)) {
                                $createWordCategory[$keyNewCategory] = $newCategory;
                            }
                        }
                        if ($deleteExercise) {
                            $this->deleteCategories[$category->getId()] = $category;
                        }
                    } else {
                        $this->deleteCategories[$category->getId()] = $category;
                    }
                }
                $array = array_diff_key($newExerciseWithIdCategory, $alreadyUpdatedCategory) + $createWordCategory;
                if (is_array($array) and !empty($array)) {
                    $this->createWordCategory($getExercise, $array);
                }
                if (!empty($this->deleteCategories)) {
                    foreach ($this->deleteCategories as $deleteCategory) {
                        if ($deleteCategory instanceof WordCategory) {
                            $getExercise->removeWordCategory($deleteCategory);
                        }
                    }
                }
            }
        }
        if ($reSpeech and $this->book->getallowSpeechSynthesis()) {
            $this->reSpeechContentArray[] = $issetExercise;
            if (array_key_exists('audioType', $exercise) and !empty($exercise['audioType'])) {
                $issetExercise->setAudioType($exercise['audioType']);
            } else {
                $issetExercise->setAudioType('Speech syntesis');
            }
        }
    }

    /**
     * @param AbstractExercise $getExercise
     * @param array $exercise
     */
    private function answerContent(AbstractExercise &$getExercise, array $exercise)
    {
        $arrCreateNewAnswer = [];
        $updatedAnswers = [];
        $arrayDeleteAnswer = [];
        if (!$getExercise->getAnswers()->isEmpty()) {
            foreach ($getExercise->getAnswers() as $kk => $answer) {
                if (array_key_exists('answers', $exercise) and is_array($exercise['answers']) and !empty($exercise['answers'])) {
                    foreach ($exercise['answers'] as $keyNewAnswer => $newAnswer) {
                        if (array_key_exists('id', $newAnswer) and $answer->getId() == $newAnswer['id']) {
                            $this->updateAnswerContent($answer, $newAnswer);
                            $updatedAnswers[$kk] = $answer;
                        } else {
                            $arrayDeleteAnswer[$kk] = $answer;
                        }

                        if (!array_key_exists('id', $newAnswer)) {
                            $arrCreateNewAnswer[$keyNewAnswer] = $newAnswer;
                        }
                    }
                } else {
                    $getExercise->removeAnswer($answer);
                }
            }
            if (is_array($arrCreateNewAnswer) and !empty($arrCreateNewAnswer)) {
                foreach ($arrCreateNewAnswer as $item) {
                    $this->createAnswerContent($item, $getExercise);
                }
            }
        }
        foreach (array_diff_key($arrayDeleteAnswer, $updatedAnswers) as $item) {
            if ($item instanceof Answer) {
                $getExercise->removeAnswer($item);
            }
        }
    }

    /**
     * @param array $item
     * @param AbstractExercise $getExercise
     */
    private function createAnswerContent(array $item, AbstractExercise &$getExercise)
    {
        $an = new Answer();
        $an->setExercise($getExercise);
        if (array_key_exists('answer', $item)) {
            $an->setAnswer($item['answer']);
        }
        if (array_key_exists('isCorrect', $item)) {
            $an->setIsCorrect($item['isCorrect']);
        }
        if (array_key_exists('isArabic', $item) and is_bool($item['isArabic'])) {
            $an->setIsArabic((bool)$item['isArabic']);
        }
        $this->om->persist($an);
        $getExercise->addAnswer($an);
    }

    /**
     * @param Answer $answer
     * @param array $newAnswer
     */
    private function updateAnswerContent(Answer &$answer, array $newAnswer)
    {
        $answer->setAnswer($newAnswer['answer']);
        $answer->setIsCorrect($newAnswer['isCorrect']);
        $answer->setIsArabic(array_key_exists('isArabic', $newAnswer) ? (bool)$newAnswer['isArabic'] : $answer->getIsArabic());
    }

    /**
     * @param CategoryExercise $getExercise
     * @param array $newCategories
     */
    private function createWordCategory(CategoryExercise &$getExercise, array $newCategories)
    {
        foreach ($newCategories as $newCategory) {
            $category = new WordCategory();
            if (is_array($newCategory) and array_key_exists('title', $newCategory)) {
                $category->setTitle($newCategory['title']);
            }
            if (is_array($newCategory) and array_key_exists('words', $newCategory)) {
                $category->setWords($newCategory['words']);
            }
            $category->setCategoryExercise($getExercise);
            $getExercise->addWordCategory($category);
            $this->om->persist($category);
        }
    }

    /**
     * @param WordCategory $category
     * @param array $newCategory
     */
    private function updateCategoryExercise(WordCategory &$category, array $newCategory)
    {
        $category->setTitle($newCategory['title']);
        $category->setWords($newCategory['words']);
    }

    /**
     * @param Page $page
     * @param array $newPage
     */
    private function headerContent(Page &$page, array $newPage)
    {
        $arrCreateNewHeader = [];
        $alreadyUpdatedHeader = [];
        $arrCreateNewHeaderWithId = [];
        if (!$page->getHeaders()->isEmpty()) {
            foreach ($page->getHeaders() as $header) {
                $deleteOlHeader = true;
                if (count($newPage['headers']) > 0) {
                    foreach ($newPage['headers'] as $keyNewHeader => $newHeader) {
                        if (array_key_exists('id', $newHeader) and $header->getId() == $newHeader['id']) {
                            $deleteOlHeader = false;
                            $alreadyUpdatedHeader[$keyNewHeader] = $newHeader;
                            $this->updateHeaderContent($header, $newHeader);
                        } elseif (!array_key_exists('id', $newHeader)) {
                            $arrCreateNewHeader[$keyNewHeader] = $newHeader;
                        } elseif (array_key_exists('id', $newHeader)) {
                            $arrCreateNewHeaderWithId[$keyNewHeader] = $newHeader;
                        }
                    }
                    if ($deleteOlHeader) {
                        $this->deleteHeaders[$header->getId()] = $header;
                    }
                } else {
                    $this->deleteHeaders[$header->getId()] = $header;
                }
            }

            if (is_array(array_diff_key($arrCreateNewHeaderWithId, $alreadyUpdatedHeader) + $arrCreateNewHeader) and
                !empty(array_diff_key($arrCreateNewHeaderWithId, $alreadyUpdatedHeader) + $arrCreateNewHeader)) {
                $this->addHeaderContent($page, array_diff_key($arrCreateNewHeaderWithId, $alreadyUpdatedHeader) + $arrCreateNewHeader);
            }
        } elseif (array_key_exists('headers', $newPage) and !empty($newPage['headers'])) {
            $this->addHeaderContent($page, $newPage['headers']);
        }
    }

    /**
     * @param HeaderContent $header
     * @param array $newHeader
     */
    private function updateHeaderContent(HeaderContent &$header, array $newHeader)
    {
        $reSpeech = false;
        if (array_key_exists('order', $newHeader) and is_int((int)$newHeader['order'])) {
            $header->setOrder((int)$newHeader['order']);
        }
        if (array_key_exists('audioType', $newHeader)) {
            $header->setAudioType($newHeader['audioType']);
        }
        if (array_key_exists('studentAudioRead', $newHeader)) {
            $header->setStudentAudioRead($newHeader['studentAudioRead']);
        }
        if (array_key_exists('hide', $newHeader)) {
            $header->setHide($newHeader['hide']);
        }
        if (array_key_exists('textColor', $newHeader)) {
            $header->setTextColor($newHeader['textColor']);
        }
        if (array_key_exists('showTextColor', $newHeader)) {
            $header->setShowTextColor($newHeader['showTextColor']);
        }
        if (array_key_exists('header', $newHeader)) {
            if ($header->getHeader() !== $newHeader['header']) {
                $reSpeech = true;
            }
            $header->setHeader(
                $this->indentService->replaceTab($newHeader['header'])
            );
        }
        if (array_key_exists('header2', $newHeader)) {
            if ($header->getHeader2() !== $newHeader['header2']) {
                $reSpeech = true;
            }
            $header->setHeader2(
                $this->indentService->replaceTab($newHeader['header2'])
            );
        }
        if (array_key_exists('header3', $newHeader)) {
            if ($header->getHeader3() !== $newHeader['header3']) {
                $reSpeech = true;
            }
            $header->setHeader3(
                $this->indentService->replaceTab($newHeader['header3'])
            );
        }
        if (array_key_exists('hideFromStudent', $newHeader)) {
            $header->setHideFromStudent((bool)$newHeader['hideFromStudent']);
        }
        if (array_key_exists('language', $newHeader) and $newHeader['language']) {
            $language = $this->om->getRepository(Language::class)->find($newHeader['language']);
            if ($language instanceof Language) {
                if ($header->getLanguage() !== $language) {
                    $reSpeech = true;
                }
                $header->setLanguage($language);
            }
        }
        if (array_key_exists('reSpeech', $newHeader) and $newHeader['reSpeech']) {
            $reSpeech = true;
        }
        if (array_key_exists('isArabic', $newHeader) and is_bool($newHeader['isArabic'])) {
            $header->setIsArabic((bool)$newHeader['isArabic']);
        }
        if (array_key_exists('audioTrack', $newHeader)) {
            if (!empty($newHeader['audioTrack'])) {
                $audio = $this->om->getRepository(Audio::class)->find($newHeader['audioTrack']);
                if ($audio instanceof Audio) {
                    $header->setAudioTrack($audio);
                }
            } else {
                $header->setAudioTrack(null);
            }
        }
        if ($reSpeech and $this->book->getallowSpeechSynthesis()) {
            if (array_key_exists('audioType', $newHeader) and !empty($newHeader['audioType'])) {
                $header->setAudioType($newHeader['audioType']);
            } else {
                $header->setAudioType('Speech syntesis');
            }
            $this->reSpeechContentArray[] = $header;
        }
    }

    /**
     * @param Page $page
     * @param array $header
     */
    private function createHeaderContent(Page &$page, array $header)
    {
        $createNewHeader = new HeaderContent();
        if (array_key_exists('order', $header)) {
            $createNewHeader->setOrder((int)$header['order']);
        }
        if (array_key_exists('hideFromStudent', $header)) {
            $createNewHeader->setHideFromStudent((bool)$header['hideFromStudent']);
        }
        if (array_key_exists('audioType', $header)) {
            $createNewHeader->setAudioType($header['audioType']);
        }
        if (array_key_exists('studentAudioRead', $header)) {
            $createNewHeader->setStudentAudioRead($header['studentAudioRead']);
        }
        if (array_key_exists('hide', $header)) {
            $createNewHeader->setHide($header['hide']);
        }
        if (array_key_exists('showTextColor', $header)) {
            $createNewHeader->setShowTextColor($header['showTextColor']);
        }
        if (array_key_exists('textColor', $header)) {
            $createNewHeader->setTextColor($header['textColor']);
        }
        if (array_key_exists('language', $header) and $header['language']) {
            $language = $this->om->getRepository(Language::class)->find($header['language']);
            if ($language instanceof Language) {
                $createNewHeader->setLanguage($language);
            }
        }
        if (array_key_exists('audioTrack', $header) and !empty($header['audioTrack'])) {
            $audio = $this->om->getRepository(Audio::class)->find($header['audioTrack']);
            if ($audio instanceof Audio) {
                $createNewHeader->setAudioTrack($audio);
            }
        }
        if (array_key_exists('isArabic', $header) and is_bool($header['isArabic'])) {
            $createNewHeader->setIsArabic((bool)$header['isArabic']);
        }
        if (isset($header['header']) and $header['header'] != null) {
            $createNewHeader->setHeader(
                $this->indentService->replaceTab($header['header'])
            );
        }
        if (isset($header['header2']) and $header['header2'] != null) {
            $createNewHeader->setHeader2(
                $this->indentService->replaceTab($header['header2'])
            );
        }
        if (isset($header['header3']) and $header['header3'] != null) {
            $createNewHeader->setHeader3(
                $this->indentService->replaceTab($header['header3'])
            );
        }

        $createNewHeader->setPage($page);
        $this->om->persist($createNewHeader);

        $page->addHeader($createNewHeader);

        if ($this->book->getallowSpeechSynthesis() and $createNewHeader->getLanguage() instanceof Language) {
            $this->reSpeechContentArray[] = $createNewHeader;
            if (array_key_exists('audioType', $header) and !empty($header['audioType'])) {
                $createNewHeader->setAudioType($header['audioType']);
            } else {
                $createNewHeader->setAudioType('Speech syntesis');
            }
        }
        $this->saveStep($createNewHeader);
    }

    /**
     * @param Page $page
     * @param array $headers
     */
    private function addHeaderContent(Page &$page, array $headers)
    {
        foreach ($headers as $header) {
            if (array_key_exists('id', $header)) {
                $issetHeader = $this->om->getRepository(HeaderContent::class)->find((int)$header['id']);
                if ($issetHeader instanceof HeaderContent) {
                    $issetHeader->getPage()->removeHeader($issetHeader);
                    $issetHeader->setPage($page);
                    $page->addHeader($issetHeader);
                    $this->updateHeaderContent($issetHeader, $header);
                    $this->addHeaders[$issetHeader->getId()] = $issetHeader;
                }
            } else {
                $this->createHeaderContent($page, $header);
            }
        }
    }


    /**
     * @param Page $page
     * @param array $newPage
     */
    private function listContent(Page &$page, array $newPage)
    {
        $arrCreateNewList = [];
        $alreadyUpdatedList = [];
        $arrCreateNewListWithId = [];
        if (!$page->getList()->isEmpty()) {
            foreach ($page->getList() as $list) {
                $deleteOlList = true;
                if (count($newPage['list']) > 0) {
                    foreach ($newPage['list'] as $keyNewList => $newList) {
                        if (array_key_exists('id', $newList) and $list->getId() == $newList['id']) {
                            $deleteOlList = false;
                            $alreadyUpdatedList[$keyNewList] = $newList;
                            $this->updateListContent($list, $newList);
                        } elseif (!array_key_exists('id', $newList)) {
                            $arrCreateNewList[$keyNewList] = $newList;
                        } elseif (array_key_exists('id', $newList)) {
                            $arrCreateNewListWithId[$keyNewList] = $newList;
                        }
                    }
                    if ($deleteOlList) {
                        $this->deleteList[$list->getId()] = $list;
                    }
                } else {
                    $this->deleteList[$list->getId()] = $list;
                }
            }

            if (is_array(array_diff_key($arrCreateNewListWithId, $alreadyUpdatedList) + $arrCreateNewList) and
                !empty(array_diff_key($arrCreateNewListWithId, $alreadyUpdatedList) + $arrCreateNewList)) {
                $this->addListContent($page, array_diff_key($arrCreateNewListWithId, $alreadyUpdatedList) + $arrCreateNewList);
            }
        } elseif (array_key_exists('list', $newPage) and !empty($newPage['list'])) {
            $this->addListContent($page, $newPage['list']);
        }
    }

    /**
     * @param ListContent $list
     * @param array $newList
     */
    private function updateListContent(ListContent &$list, array $newList)
    {
        $reSpeech = false;
        if (array_key_exists('order', $newList) and is_int((int)$newList['order'])) {
            $list->setOrder((int)$newList['order']);
        }
        if (array_key_exists('audioType', $newList)) {
            $list->setAudioType($newList['audioType']);
        }
        if (array_key_exists('hide', $newList)) {
            $list->setHide($newList['hide']);
        }
        if (array_key_exists('data', $newList)) {
            if ($list->getData() !== $newList['data']) {
                $reSpeech = true;
            }
            $list->setData(is_null($newList['data']) ? [] : $newList['data']);
        }
        if (array_key_exists('list_type', $newList)) {
            if ($list->getListType() !== $newList['list_type']) {
                $reSpeech = true;
            }
            $list->setListType($newList['list_type']);
        }
        if (array_key_exists('hideFromStudent', $newList)) {
            $list->setHideFromStudent((bool)$newList['hideFromStudent']);
        }
        if (array_key_exists('language', $newList) and $newList['language']) {
            $language = $this->om->getRepository(Language::class)->find($newList['language']);
            if ($language instanceof Language) {
                if ($list->getLanguage() !== $language) {
                    $reSpeech = true;
                }
                $list->setLanguage($language);
            }
        }
        if (array_key_exists('reSpeech', $newList) and $newList['reSpeech']) {
            $reSpeech = true;
        }
        if (array_key_exists('isArabic', $newList) and is_bool($newList['isArabic'])) {
            $list->setIsArabic((bool)$newList['isArabic']);
        }
        if (array_key_exists('audioTrack', $newList)) {
            if (!empty($newList['audioTrack'])) {
                $audio = $this->om->getRepository(Audio::class)->find($newList['audioTrack']);
                if ($audio instanceof Audio) {
                    $list->setAudioTrack($audio);
                }
            } else {
                $list->setAudioTrack(null);
            }
        }
        if ($reSpeech and $this->book->getallowSpeechSynthesis()) {
            if (array_key_exists('audioType', $newList) and !empty($newList['audioType'])) {
                $list->setAudioType($newList['audioType']);
            } else {
                $list->setAudioType('Speech syntesis');
            }
            $this->reSpeechContentArray[] = $list;
        }
    }

    /**
     * @param Page $page
     * @param array $list
     */
    private function createListContent(Page &$page, array $list)
    {
        $createNewList = new ListContent();
        if (array_key_exists('order', $list)) {
            $createNewList->setOrder((int)$list['order']);
        }
        if (array_key_exists('hideFromStudent', $list)) {
            $createNewList->setHideFromStudent((bool)$list['hideFromStudent']);
        }
        if (array_key_exists('audioType', $list)) {
            $createNewList->setAudioType($list['audioType']);
        }
        if (array_key_exists('hide', $list)) {
            $createNewList->setHide($list['hide']);
        }
        if (array_key_exists('language', $list) and $list['language']) {
            $language = $this->om->getRepository(Language::class)->find($list['language']);
            if ($language instanceof Language) {
                $createNewList->setLanguage($language);
            }
        }
        if (array_key_exists('audioTrack', $list) and !empty($list['audioTrack'])) {
            $audio = $this->om->getRepository(Audio::class)->find($list['audioTrack']);
            if ($audio instanceof Audio) {
                $createNewList->setAudioTrack($audio);
            }
        }
        if (array_key_exists('isArabic', $list) and is_bool($list['isArabic'])) {
            $createNewList->setIsArabic((bool)$list['isArabic']);
        }
        if (isset($list['data'])) {
            $createNewList->setData(is_null($list['data']) ? [] : $list['data']);
        }
        if (isset($list['list_type']) and $list['list_type'] != null) {
            $createNewList->setListType($list['list_type']);
        }

        $createNewList->setPage($page);
        $this->om->persist($createNewList);

        $page->addList($createNewList);

        if ($this->book->getallowSpeechSynthesis() and $createNewList->getLanguage() instanceof Language) {
            $this->reSpeechContentArray[] = $createNewList;
            if (array_key_exists('audioType', $list) and !empty($list['audioType'])) {
                $createNewList->setAudioType($list['audioType']);
            } else {
                $createNewList->setAudioType('Speech syntesis');
            }
        }
        $this->saveStep($createNewList);
    }

    /**
     * @param Page $page
     * @param array $lists
     */
    private function addListContent(Page &$page, array $lists)
    {
        foreach ($lists as $list) {
            if (array_key_exists('id', $list)) {
                $issetList = $this->om->getRepository(ListContent::class)->find((int)$list['id']);
                if ($issetList instanceof ListContent) {
                    $issetList->getPage()->removeList($issetList);
                    $issetList->setPage($page);
                    $page->addList($issetList);
                    $this->updateListContent($issetList, $list);
                    $this->addLists[$issetList->getId()] = $issetList;
                }
            } else {
                $this->createListContent($page, $list);
            }
        }
    }

    /**
     * @param Page $page
     * @param array $newPage
     */
    private function multimediaContent(Page &$page, array $newPage)
    {
        if (!$page->getMultimedias()->isEmpty()) {
            $arrCreateNewMultimedia = [];
            $arrCreateNewMultimediaWithId = [];
            $alreadyUpdatedMultimedia = [];
            foreach ($page->getMultimedias() as $multimedia) {
                $deleteOlMultimedia = true;
                if (array_key_exists('multimedias', $newPage) and !empty($newPage['multimedias'])) {
                    foreach ($newPage['multimedias'] as $keyNewMultimedia => $newMultimedia) {
                        if (array_key_exists('id', $newMultimedia) and $multimedia->getId() == $newMultimedia['id']) {
                            $deleteOlMultimedia = false;
                            $this->updateMultimediaContent($multimedia, $newMultimedia);
                            $alreadyUpdatedMultimedia[$keyNewMultimedia] = $newMultimedia;
                        } elseif (!array_key_exists('id', $newMultimedia)) {
                            $arrCreateNewMultimedia[$keyNewMultimedia] = $newMultimedia;
                        } elseif (array_key_exists('id', $newMultimedia)) {
                            $arrCreateNewMultimediaWithId[$keyNewMultimedia] = $newMultimedia;
                        }
                    }
                    if ($deleteOlMultimedia) {
                        $this->deleteMultimedia[$multimedia->getId()] = $multimedia;
                    }
                } else {
                    $this->deleteMultimedia[$multimedia->getId()] = $multimedia;
                }
            }
            if (is_array(array_diff_key($arrCreateNewMultimediaWithId, $alreadyUpdatedMultimedia) + $arrCreateNewMultimedia) and
                !empty(array_diff_key($arrCreateNewMultimediaWithId, $alreadyUpdatedMultimedia) + $arrCreateNewMultimedia)) {
                $this->addMultimediaContent($page, array_diff_key($arrCreateNewMultimediaWithId, $alreadyUpdatedMultimedia) + $arrCreateNewMultimedia);
            }
        } elseif (array_key_exists('multimedias', $newPage) and !empty($newPage['multimedias'])) {
            $this->addMultimediaContent($page, $newPage['multimedias']);
        }
    }

    /**
     * @param Page $page
     * @param array $multimedia
     */
    private function addMultimediaContent(Page &$page, array $multimedia)
    {
        foreach ($multimedia as $value) {
            if (array_key_exists('id', $value)) {
                $issetMultimedia = $this->om->getRepository(MultimediaContent::class)->find((int)$value['id']);
                if ($issetMultimedia instanceof MultimediaContent) {
                    $issetMultimedia->getPage()->removeMultimedia($issetMultimedia);
                    $issetMultimedia->setPage($page);
                    $page->addMultimedia($issetMultimedia);
                    $this->updateMultimediaContent($issetMultimedia, $value);
                    $this->addMultimedia[$issetMultimedia->getId()] = $issetMultimedia;
                }
            } else {
                $this->createMultimediaContent($value, $page);
            }
        }
    }

    /**
     * @param array $arrCreateNewMultimedia
     * @param Page $page
     */
    private function createMultimediaContent(array $arrCreateNewMultimedia, Page &$page)
    {
        $createNewMultimedia = new MultimediaContent();
        if (array_key_exists('order', $arrCreateNewMultimedia) and is_int($arrCreateNewMultimedia['order'])) {
            $createNewMultimedia->setOrder((int)$arrCreateNewMultimedia['order']);
        }
        if (array_key_exists('hideFromStudent', $arrCreateNewMultimedia)) {
            $createNewMultimedia->setHideFromStudent((bool)$arrCreateNewMultimedia['hideFromStudent']);
        }
        if (array_key_exists('image', $arrCreateNewMultimedia)) {
            $image = $this->om->getRepository(Image::class)->find((int)$arrCreateNewMultimedia['image']);
            if ($image instanceof Image) {
                $createNewMultimedia->setImage($image);
            }
        }
        if (array_key_exists('video', $arrCreateNewMultimedia)) {
            $video = $this->om->getRepository(Video::class)->find((int)$arrCreateNewMultimedia['video']);
            if ($video instanceof Video) {
                $createNewMultimedia->setVideo($video);
            }
        }
        if (array_key_exists('linkVideo', $arrCreateNewMultimedia)) {
            $createNewMultimedia->setLinkVideo((string)$arrCreateNewMultimedia['linkVideo']);
        }
        if (array_key_exists('properties', $arrCreateNewMultimedia)) {
            if (array_key_exists('id', $arrCreateNewMultimedia['properties'])) {
                $this->updateMultimediaProperty($createNewMultimedia, $arrCreateNewMultimedia['properties']);
            } else {
                $this->createMultimediaProperty($createNewMultimedia, $arrCreateNewMultimedia['properties']);
            }
        }
        if (array_key_exists('audioType', $arrCreateNewMultimedia)) {
            $createNewMultimedia->setAudioType($arrCreateNewMultimedia['audioType']);
        }

        if (array_key_exists('language', $arrCreateNewMultimedia) and $arrCreateNewMultimedia['language']) {
            $language = $this->om->getRepository(Language::class)->find($arrCreateNewMultimedia['language']);
            if ($language instanceof Language) {
                $createNewMultimedia->setLanguage($language);
            }
        }
        if (array_key_exists('audioTrack', $arrCreateNewMultimedia) and is_int($arrCreateNewMultimedia['audioTrack'])) {
            $audio = $this->om->getRepository(Audio::class)->find($arrCreateNewMultimedia['audioTrack']);
            if ($audio instanceof Audio) {
                $createNewMultimedia->setAudioTrack($audio);
            }
        }
        if (array_key_exists('info', $arrCreateNewMultimedia)) {
            $createNewMultimedia->setInfo($arrCreateNewMultimedia['info']);
        }

        if ($this->book->getallowSpeechSynthesis() and $createNewMultimedia->getLanguage() instanceof Language) {
            $this->reSpeechContentArray[] = $createNewMultimedia;
            if (array_key_exists('audioType', $arrCreateNewMultimedia) and !empty($arrCreateNewMultimedia['audioType'])) {
                $createNewMultimedia->setAudioType($arrCreateNewMultimedia['audioType']);
            } else {
                $createNewMultimedia->setAudioType('Speech syntesis');
            }
        }
        $this->om->persist($createNewMultimedia);
        $this->saveStep($createNewMultimedia);
        $page->addMultimedia($createNewMultimedia);
    }

    /**
     * @param MultimediaContent $multimedia
     * @param array $newMultimedia
     */
    private function updateMultimediaContent(MultimediaContent &$multimedia, array $newMultimedia)
    {
        $reSpeech = false;

        if (array_key_exists('order', $newMultimedia)) {
            $multimedia->setOrder((int)$newMultimedia['order']);
        }
        if (array_key_exists('hideFromStudent', $newMultimedia)) {
            $multimedia->setHideFromStudent((bool)$newMultimedia['hideFromStudent']);
        }
        if (array_key_exists('image', $newMultimedia) and isset($newMultimedia['image'])) {
            $image = $this->om->getRepository(Image::class)->find((int)$newMultimedia['image']);
            if ($image instanceof Image) {
                $multimedia->setImage($image);
            }
        }
        if (array_key_exists('video', $newMultimedia) and isset($newMultimedia['video'])) {
            $video = $this->om->getRepository(Video::class)->find((int)$newMultimedia['video']);
            if ($video instanceof Video) {
                $multimedia->setVideo($video);
            }
        }
        if (array_key_exists('linkVideo', $newMultimedia) and isset($newMultimedia['linkVideo'])) {
            $multimedia->setLinkVideo($newMultimedia['linkVideo']);
        }

        if (array_key_exists('properties', $newMultimedia) and !empty($newMultimedia['properties'])) {
            if (array_key_exists('id', $newMultimedia['properties'])) {
                $this->updateMultimediaProperty($multimedia, $newMultimedia['properties']);
            } else {
                $this->createMultimediaProperty($multimedia, $newMultimedia['properties']);
            }
        }
        if (array_key_exists('audioType', $newMultimedia)) {
            $multimedia->setAudioType($newMultimedia['audioType']);
        }
        if (array_key_exists('reSpeech', $newMultimedia) and $newMultimedia['reSpeech']) {
            $reSpeech = true;
        }
        if (array_key_exists('info', $newMultimedia)) {
            $multimedia->setInfo($newMultimedia['info']);
        }
        if (array_key_exists('language', $newMultimedia) and $newMultimedia['language']) {
            $language = $this->om->getRepository(Language::class)->find($newMultimedia['language']);
            if ($language instanceof Language) {
                $multimedia->setLanguage($language);
            }
        }
        if (array_key_exists('audioTrack', $newMultimedia) and is_int($newMultimedia['audioTrack'])) {
            $audio = $this->om->getRepository(Audio::class)->find($newMultimedia['audioTrack']);
            if ($audio instanceof Audio) {
                $multimedia->setAudioTrack($audio);
            }
        }
        if ($reSpeech and $this->book->getallowSpeechSynthesis()) {
            $this->reSpeechContentArray[] = $multimedia;
            if (array_key_exists('audioType', $newMultimedia) and !empty($newMultimedia['audioType'])) {
                $multimedia->setAudioType($newMultimedia['audioType']);
            } else {
                $multimedia->setAudioType('Speech syntesis');
            }
        }
    }

    /**
     * @param MultimediaContent $multimedia
     * @param array $newMultimediaProperties
     */
    private function updateMultimediaProperty(MultimediaContent &$multimedia, array $newMultimediaProperties)
    {
        $property = $this->om->getRepository(Property::class)->find((int)$newMultimediaProperties['id']);
        if ($property instanceof Property) {
            $property->setAutoStart(isset($newMultimediaProperties['autoStart']) ? $newMultimediaProperties['autoStart'] : $property->getAutoStart());
            $property->setBorder(isset($newMultimediaProperties['border']) ? $newMultimediaProperties['border'] : $property->getBorder());
            $property->setIsShadowed(isset($newMultimediaProperties['isShadowed']) ? $newMultimediaProperties['isShadowed'] : $property->getIsShadowed());
            $property->setScale(isset($newMultimediaProperties['scale']) ? $newMultimediaProperties['scale'] : $property->getScale());
            $property->setVideoLoop(isset($newMultimediaProperties['videoLoop']) ? $newMultimediaProperties['videoLoop'] : $property->getVideoLoop());
            $property->setFullWidth(isset($newMultimediaProperties['fullWidth']) ? $newMultimediaProperties['fullWidth'] : $property->getFullWidth());

            $multimedia->setProperties($property);
        }
    }

    /**
     * @param MultimediaContent $multimedia
     * @param array $newMultimediaProperties
     */
    private function createMultimediaProperty(MultimediaContent &$multimedia, array $newMultimediaProperties)
    {
        $property = new Property();
        $property->setAutoStart(isset($newMultimediaProperties['autoStart']) ? $newMultimediaProperties['autoStart'] : null);
        $property->setBorder(isset($newMultimediaProperties['border']) ? $newMultimediaProperties['border'] : null);
        $property->setIsShadowed(isset($newMultimediaProperties['isShadowed']) ? $newMultimediaProperties['isShadowed'] : null);
        $property->setScale(isset($newMultimediaProperties['scale']) ? $newMultimediaProperties['scale'] : null);
        $property->setVideoLoop(isset($newMultimediaProperties['videoLoop']) ? $newMultimediaProperties['videoLoop'] : null);

        $property->setFullWidth(isset($newMultimediaProperties['fullWidth']) ? $newMultimediaProperties['fullWidth'] : null);
        $multimedia->setProperties($property);
    }

    /**
     * @param Page $page
     * @param array $newPage
     */
    private function textContent(Page &$page, array $newPage)
    {
        if (!$page->getTexts()->isEmpty()) {
            $arrCreateNewTexts = [];
            $arrCreateNewTextsWithId = [];
            $alreadyUpdatedText = [];
            foreach ($page->getTexts() as $text) {
                $deleteOldText = true;
                if (array_key_exists('texts', $newPage) and !empty($newPage['texts'])) {
                    foreach ($newPage['texts'] as $keyNewText => $newText) {
                        if (array_key_exists('id', $newText) and $text->getId() == $newText['id']) {
                            $deleteOldText = false;
                            $this->updateTextContent($text, $newText);
                            $alreadyUpdatedText[$keyNewText] = $newText;
                        } elseif (!array_key_exists('id', $newText)) {
                            $arrCreateNewTexts[$keyNewText] = $newText;
                        } elseif (array_key_exists('id', $newText)) {
                            $arrCreateNewTextsWithId[$keyNewText] = $newText;
                        }
                    }
                    if ($deleteOldText) {
                        $this->deleteText[$text->getId()] = $text;
                    }
                } else {
                    $this->deleteText[$text->getId()] = $text;
                }
            }
            if (is_array(array_diff_key($arrCreateNewTextsWithId, $alreadyUpdatedText) + $arrCreateNewTexts) and
                !empty(array_diff_key($arrCreateNewTextsWithId, $alreadyUpdatedText) + $arrCreateNewTexts)) {
                $this->addTextContent($page, array_diff_key($arrCreateNewTextsWithId, $alreadyUpdatedText) + $arrCreateNewTexts);
            }
        } elseif (array_key_exists('texts', $newPage) and !empty($newPage['texts'])) {
            $this->addTextContent($page, $newPage['texts']);
        }
    }

    /**
     * @param Page $page
     * @param array $texts
     */
    private function addTextContent(Page &$page, array $texts)
    {
        foreach ($texts as $value) {
            if (array_key_exists('id', $value)) {
                $issetTexts = $this->om->getRepository(TextContent::class)->find((int)$value['id']);
                if ($issetTexts instanceof TextContent) {
                    $issetTexts->getPage()->removeText($issetTexts);
                    $issetTexts->setPage($page);
                    $page->addText($issetTexts);
                    $this->updateTextContent($issetTexts, $value);
                    $this->addText[$issetTexts->getId()] = $issetTexts;
                }
            } else {
                $this->createTextContent($page, $value);
            }
        }
    }

    /**
     * @param Page $page
     * @param array $newTexts
     */
    private function createTextContent(Page &$page, array $newTexts)
    {
        $createNewText = new TextContent();
        if (array_key_exists('order', $newTexts)) {
            $createNewText->setOrder((int)$newTexts['order']);
        }
        if (array_key_exists('hideFromStudent', $newTexts)) {
            $createNewText->setHideFromStudent((bool)$newTexts['hideFromStudent']);
        }
        if (array_key_exists('audioType', $newTexts)) {
            $createNewText->setAudioType($newTexts['audioType']);
        }
        if (array_key_exists('showBorder', $newTexts)) {
            $createNewText->setShowBorder((bool)$newTexts['showBorder']);
        }
        if (array_key_exists('showColor', $newTexts)) {
            $createNewText->setShowColor((bool)$newTexts['showColor']);
        }
        if (array_key_exists('studentAudioRead', $newTexts)) {
            $createNewText->setStudentAudioRead($newTexts['studentAudioRead']);
        }
        if (array_key_exists('language', $newTexts) and $newTexts['language']) {
            $language = $this->om->getRepository(Language::class)->find($newTexts['language']);
            if ($language instanceof Language) {
                $createNewText->setLanguage($language);
            }
        }
        if (array_key_exists('audioTrack', $newTexts) and is_int($newTexts['audioTrack'])) {
            $audio = $this->om->getRepository(Audio::class)->find($newTexts['audioTrack']);
            if ($audio instanceof Audio) {
                $createNewText->setAudioTrack($audio);
            }
        }
        if (array_key_exists('info', $newTexts)) {
            $createNewText->setInfo(
                $this->indentService->replaceTab($newTexts['info'])
            );
        }
        if (array_key_exists('paragraph', $newTexts)) {
            $createNewText->setParagraph(
                $this->indentService->replaceTab($newTexts['paragraph'])
            );
        }
        if (array_key_exists('color', $newTexts)) {
            $createNewText->setColor($newTexts['color']);
        }
        if (array_key_exists('showTextColor', $newTexts)) {
            $createNewText->setShowTextColor($newTexts['showTextColor']);
        }
        if (array_key_exists('textColor', $newTexts)) {
            $createNewText->setTextColor($newTexts['textColor']);
        }
        if (array_key_exists('borderColor', $newTexts)) {
            $createNewText->setBorderColor($newTexts['borderColor']);
        }
        if (array_key_exists('isArabic', $newTexts) and is_bool($newTexts['isArabic'])) {
            $createNewText->setIsArabic($newTexts['isArabic']);
        }
        if (array_key_exists('smallText', $newTexts) and is_bool($newTexts['smallText'])) {
            $createNewText->setSmallText($newTexts['smallText']);
        }
        $createNewText->setPage($page);
        $this->om->persist($createNewText);
        $page->addText($createNewText);
        $this->saveStep($createNewText);
        if ($this->book->getallowSpeechSynthesis() and $createNewText->getLanguage() instanceof Language) {
            $this->reSpeechContentArray[] = $createNewText;
            if (array_key_exists('audioType', $newTexts) and !empty($newTexts['audioType'])) {
                $createNewText->setAudioType($newTexts['audioType']);
            } else {
                $createNewText->setAudioType('Speech syntesis');
            }
        }
    }

    /**
     * @param TextContent $text
     * @param array $newText
     */
    private function updateTextContent(TextContent &$text, array $newText)
    {
        $reSpeech = false;
        if (array_key_exists('order', $newText)) {
            $text->setOrder((int)$newText['order']);
        }
        if (array_key_exists('hideFromStudent', $newText)) {
            $text->setHideFromStudent((bool)$newText['hideFromStudent']);
        }
        if (array_key_exists('audioType', $newText)) {
            $text->setAudioType($newText['audioType']);
        }
        if (array_key_exists('showBorder', $newText)) {
            $text->setShowBorder($newText['showBorder']);
        }
        if (array_key_exists('showColor', $newText)) {
            $text->setShowColor($newText['showColor']);
        }
        if (array_key_exists('studentAudioRead', $newText)) {
            $text->setStudentAudioRead($newText['studentAudioRead']);
        }
        if (array_key_exists('language', $newText) and $newText['language']) {
            $language = $this->om->getRepository(Language::class)->find($newText['language']);
            if ($language instanceof Language) {
                if ($text->getLanguage() !== $language) {
                    $reSpeech = true;
                }
                $text->setLanguage($language);
            }
        }
        if (array_key_exists('reSpeech', $newText) and $newText['reSpeech']) {
            $reSpeech = true;
        }
        if (array_key_exists('audioTrack', $newText)) {
            if (is_int($newText['audioTrack'])) {
                $audio = $this->om->getRepository(Audio::class)->find($newText['audioTrack']);
                if ($audio instanceof Audio) {
                    $text->setAudioTrack($audio);
                }
            } else {
                $text->setAudioTrack(null);
            }
        }
        if (array_key_exists('info', $newText)) {
            if ($text->getInfo() !== $newText['info']) {
                $reSpeech = true;
            }
            $text->setInfo(
                $this->indentService->replaceTab($newText['info'])
            );
        }
        if (array_key_exists('paragraph', $newText)) {
            if ($text->getParagraph() !== $newText['paragraph']) {
                $reSpeech = true;
            }
            $text->setParagraph(
                $this->indentService->replaceTab($newText['paragraph'])
            );
        }
        if (array_key_exists('color', $newText)) {
            $text->setColor($newText['color']);
        }
        if (array_key_exists('showTextColor', $newText)) {
            $text->setShowTextColor($newText['showTextColor']);
        }
        if (array_key_exists('textColor', $newText)) {
            $text->setTextColor($newText['textColor']);
        }
        if (array_key_exists('borderColor', $newText)) {
            $text->setBorderColor($newText['borderColor']);
        }
        if (array_key_exists('isArabic', $newText) and is_bool($newText['isArabic'])) {
            $text->setIsArabic((bool)$newText['isArabic']);
        }
        if (array_key_exists('smallText', $newText) and is_bool($newText['smallText'])) {
            $text->setSmallText((bool)$newText['smallText']);
        }
        if ($reSpeech and $this->book->getallowSpeechSynthesis()) {
            $this->reSpeechContentArray[] = $text;
            if (array_key_exists('audioType', $newText) and !empty($newText['audioType'])) {
                $text->setAudioType($newText['audioType']);
            } else {
                $text->setAudioType('Speech syntesis');
            }
        }
    }

    /**
     * @param array $content
     */
    private function removeContent(array $content)
    {
        /** @var AbstractContent $value */
        foreach ($content as $value) {
            if ($value instanceof AbstractContent) {
                $page = $value->getPage();
                if ($value instanceof ExerciseContent) {
                    $page->removeExercise($value);
                }
                if ($value instanceof HeaderContent) {
                    $page->removeHeader($value);
                }
                if ($value instanceof MultimediaContent) {
                    $page->removeMultimedia($value);
                }
                if ($value instanceof TextContent) {
                    $page->removeText($value);
                }
                if ($value instanceof ListContent) {
                    $page->removeList($value);
                }
                $this->saveStep();
            }
        }
    }

    private function replaceBookEntityData($newVersionBook)
    {
        foreach ($newVersionBook as $kk => $newElement) {
            if (in_array($kk, $this->replaceArray)) {
                switch ($kk) {
                    case 'avatar':
                        if (is_null($newElement)) {
                            $avatar = $newElement;
                        } else {
                            $avatar = $this->om->getRepository(Image::class)->find($newElement);
                        }
                        $this->book->setAvatar($avatar);
                        break;
                    case 'title':
                        $this->book->setTitle($newElement);
                        break;
                    case 'isCompleted':
                        $this->book->setIsCompleted($newElement);
                        break;
                    case 'author':
                        $this->book->setAuthor($newElement);
                        break;
                    case 'intro':
                        $this->book->setIntro($newElement);
                        break;
                    case 'isArabic':
                        $this->book->setIsArabic((bool)$newElement);
                        break;
                    case 'isIndent':
                        $this->book->setIsIndent((bool)$newElement);
                        break;
                    case 'canEdit':
                        $this->book->setCanEdit((bool)$newElement);
                        break;
                    case 'productId':
                        $this->book->setProductId((string)$newElement);
                        break;
                    case 'pagesPreview':
                        $this->book->setPagesPreview((array)$newElement);
                        break;
                    case 'isFree':
                        $this->book->setIsFree((bool)$newElement);
                        break;
                    case 'isScrollPage':
                        $this->book->setIsScrollPage($newElement);
                        break;
                    case 'isPrinted':
                        $this->book->setIsPrinted((bool)$newElement);
                        break;
                    case 'allowSpeechSynthesis':
                        $this->book->setallowSpeechSynthesis((bool)$newElement);
                        break;
                    case 'speechSyntezStudentAnswer':
                        $this->book->setSpeechSyntezStudentAnswer((bool)$newElement);
                        break;
                    case 'status':
                        $this->book->setStatus((string)$newElement);
                        break;
                    case 'demoStatus':
                        $this->book->setDemoStatus((string)$newElement);
                        break;
                    case 'templateType':
                        $this->book->setTemplateType($newElement);
                        break;
                    case 'language':
                        if (is_null($newElement)) {
                            $language = $newElement;
                        } else {
                            $language = $this->om->getRepository(Language::class)->find((int)$newElement);
                        }
                        $this->book->setLanguage($language);
                        break;
                }
            }
        }
        $this->book->setUpdatedAt(new \DateTime());
    }

    private function wordsDefinitions($newVersionBook)
    {
        $newWord = [];
        $createwordsDefinitions = function ($definition, $book, ObjectManager $em) {
            $def = new WordDefinition();
            $def->setBook($book);
            $def->setDefinition($definition['definition']);
            $def->setWord($definition['word']);

            $def->setIsArabic(array_key_exists('isArabic', $definition) ? (bool)$definition['isArabic'] : $def->getIsArabic());

            $em->persist($def);

            return $def;
        };
        if (!$this->book->getWordsDefinitions()->isEmpty()) {
            foreach ($this->book->getWordsDefinitions() as $wordsDefinition) {
                $wordFlag = false;
                if (is_array($newVersionBook) and array_key_exists('wordsDefinitions', $newVersionBook)) {
                    foreach ($newVersionBook['wordsDefinitions'] as $keyWord => $definition) {
                        if (array_key_exists('id', $definition) and $wordsDefinition->getId() == $definition['id']) {
                            $wordFlag = true;
                            $wordsDefinition->setWord($definition['word']);
                            $wordsDefinition->setDefinition($definition['definition']);
                        } elseif (!array_key_exists('id', $definition)) {
                            $newWord[$keyWord] = $definition;
                        }
                    }
                } else {
                    $this->om->remove($wordsDefinition);
                    $this->book->removeWordsDefinition($wordsDefinition);
                }
                if (!$wordFlag) {
                    $this->om->remove($wordsDefinition);
                    $this->book->removeWordsDefinition($wordsDefinition);
                }
            }
        } elseif (is_array($newVersionBook) and array_key_exists('wordsDefinitions', $newVersionBook)) {
            foreach ($newVersionBook['wordsDefinitions'] as $definition) {
                $this->book->addWordsDefinition($createwordsDefinitions($definition, $this->book, $this->om));
            }
        }
        if (is_array($newWord) and !empty($newWord)) {
            foreach ($newWord as $definition) {
                $this->book->addWordsDefinition($createwordsDefinitions($definition, $this->book, $this->om));
            }
        }
    }

    /**
     * @param array $reSpeechContentArray
     */
    private function reSpeechContent(array $reSpeechContentArray)
    {
        foreach ($reSpeechContentArray as $content) {
            if (!$content instanceof AbstractContent) continue;
            $this->producer->sendEvent('generateAudioSpeech', serialize($content));
        }
    }

    private function saveStep($object = null)
    {
        if ($this->user instanceof User) {
            if (is_null($object)) {
                $this->om->flush();
            } else {
                $this->om->flush($object);
            }
        } elseif ($this->book->getStatus() == Book::STATUS_DEMO and $this->book->getDemoStatus() === Book::STATUS_DEMO_VIEW_EDIT_PLUS) {
            if (is_null($object)) {
                $this->om->flush();
            } else {
                $this->om->flush($object);
            }
        }
    }

    private function gapContent(GapExercise &$getExercise, array $exercise)
    {
        $arrCreateNewAnswer = [];
        if (!$getExercise->getGaps()->isEmpty() and \array_key_exists('gaps', $exercise)) {
            /**  @var GapExercise\GapExerciseGap $gap */
            foreach ($getExercise->getGaps() as $kk => $gap) {
                $delete = true;
                foreach ($exercise['gaps'] as $keyNewAnswer => $newAnswer) {
                    if (\array_key_exists('id', $newAnswer) and $gap->getId() == $newAnswer['id']) {
                        $delete = false;
                        $this->updateGapContent($gap, $newAnswer);
                        $this->gapAnswerContent($gap, $newAnswer);
                    }

                    if (!\array_key_exists('id', $newAnswer)) {
                        $arrCreateNewAnswer[$keyNewAnswer] = $newAnswer;
                    }
                }
                if ($delete) {
                    $this->om->remove($gap);
                }
            }
            foreach ($arrCreateNewAnswer as $item) {
                $this->createGapContent($item, $getExercise);
            }
        } elseif (!\array_key_exists('gaps', $exercise)) {
            foreach ($exercise['gaps'] as $gap) {
                $this->createGapContent($gap, $getExercise);
            }
        }
    }

    /**
     * @param Answer $answer
     * @param array $newAnswer
     */
    private function updateGapContent(GapExercise\GapExerciseGap &$gap, array $newAnswer)
    {
        $gap->setValue($newAnswer['value']);
        $gap->setType($newAnswer['type']);
        $gap->setOrder($newAnswer['order']);
    }

    private function createGapContent(array $gap, GapExercise &$newExercise)
    {
        $newGap = new GapExercise\GapExerciseGap();
        $newGap->setOrder($gap['order'] ?? null);
        $newGap->setType($gap['type'] ?? null);
        $newGap->setValue($gap['value'] ?? null);
        $newGap->setGapExercise($newExercise);

        if (\array_key_exists('answers', $gap)) {
            foreach ($gap['answers'] as $answer) {
                $this->createGapAnswerContent($answer, $newGap);
            }
        }
        $newExercise->addGap($newGap);
    }

    private function gapAnswerContent(GapExercise\GapExerciseGap &$getExercise, array $exercise)
    {
        $createArray = [];
        if (!$getExercise->getAnswers()->isEmpty() and \array_key_exists('answers', $exercise)) {
            /** @var GapExercise\GapExerciseGapAnswer $oldAnswer */
            foreach ($getExercise->getAnswers() as $oldAnswer){
                $delete = true;
                foreach ($exercise['answers'] as $key => $answer){
                    if (\array_key_exists('id', $answer) and $answer['id'] == $oldAnswer->getId()){
                        $delete = false;
                        $this->updateGapAnswerContent($oldAnswer, $answer);
                    }

                    if (!\array_key_exists('id', $answer)){
                        $createArray[$key] = $answer;
                    }
                }
                if ($delete){
                    $this->om->remove($oldAnswer);
                    $getExercise->removeAnswer($oldAnswer);
                    $this->saveStep();
                }
            }
            foreach ($createArray as $answer){
                $this->createGapAnswerContent($answer, $getExercise);
            }
        } elseif (\array_key_exists('answers', $exercise)) {
            foreach ($exercise['answers'] as $answer) {
                $this->createGapAnswerContent($answer, $getExercise);
            }
        }
    }

    private function createGapAnswerContent($answer, GapExercise\GapExerciseGap &$newGap)
    {
        $newGapAnswer = new GapExercise\GapExerciseGapAnswer();
        $newGapAnswer->setAnswer($answer['answer']);
        $newGapAnswer->setIsCorrect($answer['isCorrect']);
        $newGapAnswer->setGap($newGap);
        $newGap->addAnswer($newGapAnswer);

        $this->om->persist($newGapAnswer);
    }

    private function updateGapAnswerContent(GapExercise\GapExerciseGapAnswer &$gapAnswer, array $newAnswer)
    {
        $gapAnswer->setIsCorrect($newAnswer['isCorrect']);
        $gapAnswer->setAnswer($newAnswer['answer']);
    }
}