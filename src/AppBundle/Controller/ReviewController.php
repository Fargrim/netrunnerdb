<?php
namespace AppBundle\Controller;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpFoundation\Response;
use AppBundle\Entity\Review;
use AppBundle\Entity\Card;
use Doctrine\ORM\EntityManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use AppBundle\Entity\Comment;
use AppBundle\Entity\Reviewcomment;
use \Doctrine\ORM\Tools\Pagination\Paginator;


class ReviewController extends Controller
{
    public function postAction(Request $request)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
        
        /* @var $user \AppBundle\Entity\User */
        $user = $this->getUser();
        if(!$user) {
            throw new UnauthorizedHttpException("You are not logged in.");
        }
        
        // a user cannot post more reviews than her reputation
        if(count($user->getReviews()) >= $user->getReputation()) {
            return new Response(json_encode("Your reputation doesn't allow you to write more reviews."));
        }
        
        $card_id = filter_var($request->get('card_id'), FILTER_SANITIZE_NUMBER_INT);
        /* @var $card Card */
        $card = $em->getRepository('AppBundle:Card')->find($card_id);
        if(!$card) {
            throw new BadRequestHttpException("Unable to find card.");
        }
        if(!$card->getPack()->getDateRelease()) {
        	return new Response(json_encode("You may not write a review for an unreleased card."));
        }
        
        // checking the user didn't already write a review for that card
        $review = $em->getRepository('AppBundle:Review')->findOneBy(array('card' => $card, 'user' => $user));
        if($review) {
            return new Response(json_encode("You cannot write more than 1 review for a given card."));
        }
        
        $review_raw = trim($request->get('review'));
        
        $review_raw = preg_replace(
                '%(?<!\()\b(?:(?:https?|ftp)://)(?:((?:(?:[a-z\d\x{00a1}-\x{ffff}]+-?)*[a-z\d\x{00a1}-\x{ffff}]+)(?:\.(?:[a-z\d\x{00a1}-\x{ffff}]+-?)*[a-z\d\x{00a1}-\x{ffff}]+)*(?:\.[a-z\x{00a1}-\x{ffff}]{2,6}))(?::\d+)?)(?:[^\s]*)?%iu',
                '[$1]($0)', $review_raw);
        
        $review_html = $this->get('texts')->markdown($review_raw);
        if(!$review_html) {
            return new Response(json_encode("Your review is empty."));
        }
        
        $review = new Review();
        $review->setCard($card);
        $review->setUser($user);
        $review->setRawtext($review_raw);
        $review->setText($review_html);
        $review->setNbvotes(0);
        
        $em->persist($review);
        
        $em->flush();
        
        return new Response(json_encode(TRUE));
    }
    
    public function editAction(Request $request)
    {

        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
        
        /* @var $user \AppBundle\Entity\User */
        $user = $this->getUser();
        if(!$user) {
            throw new UnauthorizedHttpException("You are not logged in.");
        }
        
        $review_id = filter_var($request->get('review_id'), FILTER_SANITIZE_NUMBER_INT);
        /* @var $review Review */
        $review = $em->getRepository('AppBundle:Review')->find($review_id);
        if(!$review) {
            throw new BadRequestHttpException("Unable to find review.");
        }
        if($review->getUser()->getId() !== $user->getId()) {
            throw new UnauthorizedHttpException("You cannot edit this review.");
        }
        
        $review_raw = trim($request->get('review'));
        
        $review_raw = preg_replace(
                '%(?<!\()\b(?:(?:https?|ftp)://)(?:((?:(?:[a-z\d\x{00a1}-\x{ffff}]+-?)*[a-z\d\x{00a1}-\x{ffff}]+)(?:\.(?:[a-z\d\x{00a1}-\x{ffff}]+-?)*[a-z\d\x{00a1}-\x{ffff}]+)*(?:\.[a-z\x{00a1}-\x{ffff}]{2,6}))(?::\d+)?)(?:[^\s]*)?%iu',
                '[$1]($0)', $review_raw);
        
        $review_html = $this->get('texts')->markdown($review_raw);
        if(!$review_html) {
            return new Response('Your review is empty.');
        }
        
        $review->setRawtext($review_raw);
        $review->setText($review_html);
        
        $em->flush();
        
        return new Response(json_encode(TRUE));
    }
    
    public function likeAction(Request $request)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
        
        $user = $this->getUser();
        if(!$user) {
            throw new UnauthorizedHttpException("You are not logged in.");
        }
        
        $review_id = filter_var($request->request->get('id'), FILTER_SANITIZE_NUMBER_INT);
        /* @var $review Review */
        $review = $em->getRepository('AppBundle:Review')->find($review_id);
        if(!$review) {
            throw new NotFoundHttpException("Unable to find review.");
        }
        
        // a user cannot vote on her own review
        if($review->getUser()->getId() != $user->getId())
        {
            // checking if the user didn't already vote on that review
            $query = $em->getRepository('AppBundle:Review')
            ->createQueryBuilder('r')
            ->innerJoin('r.votes', 'u')
            ->where('r.id = :review_id')
            ->andWhere('u.id = :user_id')
            ->setParameter('review_id', $review_id)
            ->setParameter('user_id', $user->getId())
            ->getQuery();
            
            $result = $query->getResult();
            if (empty($result))
            {
                $author = $review->getUser();
                $author->setReputation($author->getReputation() + 1);
                $user->addReviewVote($review);
                $review->setNbvotes($review->getNbvotes() + 1);
                $em->flush();
            }
        }
        return new Response(count($review->getVotes()));
        
    }
    
    public function removeAction($id, Request $request)
    {
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
        
        $user = $this->getUser();
        if(!$user || !in_array('ROLE_SUPER_ADMIN', $user->getRoles())) {
            throw new UnauthorizedHttpException('No user or not admin');
        }
        

        $review_id = filter_var($request->get('id'), FILTER_SANITIZE_NUMBER_INT);
        /* @var $review Review */
        $review = $em->getRepository('AppBundle:Review')->find($review_id);
        if(!$review) {
            throw new NotFoundHttpException("Unable to find review.");
        }
        
        $votes = $review->getVotes();
        foreach($votes as $vote) {
            $review->removeVote($vote);
        }
        $em->remove($review);
        $em->flush();
        
        return new Response('Done');
    }
    
    public function listAction($page = 1, Request $request)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($this->container->getParameter('short_cache'));
        
        $limit = 5;
        if ($page < 1)
            $page = 1;
        $start = ($page - 1) * $limit;
        
        $pagetitle = "Card Reviews";
        
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
        
        $dql = "SELECT r FROM AppBundle:Review r JOIN r.card c JOIN c.pack p WHERE p.dateRelease IS NOT NULL ORDER BY r.dateCreation DESC";
        $query = $em->createQuery($dql)->setFirstResult($start)->setMaxResults($limit);
        
        $paginator = new Paginator($query, false);
        $maxcount = count($paginator);
        
        $reviews = array();
        foreach ($paginator as $review) {
            $reviews[] = $review;
        }
        
        // pagination : calcul de nbpages // currpage // prevpage // nextpage
        // à partir de $start, $limit, $count, $maxcount, $page
        
        $currpage = $page;
        $prevpage = max(1, $currpage - 1);
        $nbpages = min(10, ceil($maxcount / $limit));
        $nextpage = min($nbpages, $currpage + 1);
        
        $route = $request->get('_route');
        
        $params = $request->query->all();
        
        $pages = array();
        for ($page = 1; $page <= $nbpages; $page ++) {
            $pages[] = array(
                    "numero" => $page,
                    "url" => $this->generateUrl($route, $params + array(
                            "page" => $page
                    )),
                    "current" => $page == $currpage
            );
        }
        
        return $this->render('AppBundle:Reviews:reviews.html.twig',
                array(
                        'pagetitle' => $pagetitle,
                        'pagedescription' => "Read the latest user-submitted reviews on the cards.",
                        'reviews' => $reviews,
                        'url' => $request->getRequestUri(),
                        'route' => $route,
                        'pages' => $pages,
                        'prevurl' => $currpage == 1 ? null : $this->generateUrl($route, $params + array(
                                "page" => $prevpage
                        )),
                        'nexturl' => $currpage == $nbpages ? null : $this->generateUrl($route, $params + array(
                                "page" => $nextpage
                        ))
                ), $response);
        
    }

    public function byauthorAction($user_id, $page = 1, Request $request)
    {
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($this->container->getParameter('short_cache'));
    
        $limit = 5;
        if ($page < 1)
            $page = 1;
        $start = ($page - 1) * $limit;
    
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();

        $user = $em->getRepository('AppBundle:User')->find($user_id);
        
        $pagetitle = "Card Reviews by ".$user->getUsername();
        
        $dql = "SELECT r FROM AppBundle:Review r WHERE r.user = :user ORDER BY r.dateCreation DESC";
        $query = $em->createQuery($dql)->setFirstResult($start)->setMaxResults($limit)->setParameter('user', $user);
    
        $paginator = new Paginator($query, false);
        $maxcount = count($paginator);
    
        $reviews = array();
        foreach ($paginator as $review) {
            $reviews[] = $review;
        }
    
        // pagination : calcul de nbpages // currpage // prevpage // nextpage
        // à partir de $start, $limit, $count, $maxcount, $page
    
        $currpage = $page;
        $prevpage = max(1, $currpage - 1);
        $nbpages = min(10, ceil($maxcount / $limit));
        $nextpage = min($nbpages, $currpage + 1);
    
        $route = $request->get('_route');
    
        $params = $request->query->all();
    
        $pages = array();
        for ($page = 1; $page <= $nbpages; $page ++) {
            $pages[] = array(
                    "numero" => $page,
                    "url" => $this->generateUrl($route, $params + array(
                            "user_id" => $user_id,
                            "page" => $page
                    )),
                    "current" => $page == $currpage
            );
        }
    
        return $this->render('AppBundle:Reviews:reviews.html.twig',
                array(
                        'pagetitle' => $pagetitle,
                        'pagedescription' => "Read the latest user-submitted reviews on the cards.",
                        'reviews' => $reviews,
                        'url' => $request->getRequestUri(),
                        'route' => $route,
                        'pages' => $pages,
                        'prevurl' => $currpage == 1 ? null : $this->generateUrl($route, $params + array(
                                "user_id" => $user_id,
                                "page" => $prevpage
                        )),
                        'nexturl' => $currpage == $nbpages ? null : $this->generateUrl($route, $params + array(
                                "user_id" => $user_id,
                                "page" => $nextpage
                        ))
                ), $response);
    
    }
    
    public function commentAction(Request $request)
    {

        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
        
        /* @var $user \AppBundle\Entity\User */
        $user = $this->getUser();
        if(!$user) {
            throw new UnauthorizedHttpException("You are not logged in.");
        }
        
        $review_id = filter_var($request->get('comment_review_id'), FILTER_SANITIZE_NUMBER_INT);
        /* @var $review Review */
        $review = $em->getRepository('AppBundle:Review')->find($review_id);
        if(!$review) {
            throw new BadRequestHttpException("Unable to find review.");
        }
        
        $comment_text = trim($request->get('comment'));
        $comment_text = htmlspecialchars($comment_text);
        if(!$comment_text) {
            return new Response('Your comment is empty.');
        }
        
        $comment = new Reviewcomment();
        $comment->setReview($review);
        $comment->setAuthor($user);
        $comment->setText($comment_text);
        
        $em->persist($comment);
        
        $em->flush();
        
        return new Response(json_encode(TRUE));
        
    }
}