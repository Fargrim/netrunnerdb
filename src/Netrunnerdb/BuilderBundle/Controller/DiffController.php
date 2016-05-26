<?php
namespace Netrunnerdb\BuilderBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;
use Netrunnerdb\BuilderBundle\Entity\Decklist;

class DiffController extends Controller
{
    public function decklistDiffAction($decklist1_id, $decklist2_id, Request $request)
    {
        if($decklist1_id > $decklist2_id)
        {
            return $this->redirect($this->generateUrl('decklists_diff', array('decklist1_id' => $decklist2_id, 'decklist2_id' => $decklist1_id)));
        }
        $response = new Response();
        $response->setPublic();
        $response->setMaxAge($this->container->getParameter('short_cache'));
        
        /* @var $em \Doctrine\ORM\EntityManager */
        $em = $this->get('doctrine')->getManager();
        /* @var $d1 Decklist */
        $d1 = $em->getRepository('NetrunnerdbBuilderBundle:Decklist')->find($decklist1_id);
        /* @var $d2 Decklist */
        $d2 = $em->getRepository('NetrunnerdbBuilderBundle:Decklist')->find($decklist2_id);
        
        if(!$d1 || !$d2) {
            throw new NotFoundHttpException("Unable to find decklists.");
        }
        
        $decks = array($d1->getContent(), $d2->getContent());
        
        list($listings, $intersect) = $this->get('diff')->diffContents($decks);
        
        $content1 = array();
        foreach($listings[0] as $code => $qty) {
            $card = $em->getRepository('NetrunnerdbCardsBundle:Card')->findOneBy(array('code' => $code));
            if($card) $content1[] = array(
                    'title' => $card->getTitle(),
                    'code' => $code,
                    'qty' => $qty
                    );
        }
        
        $content2 = array();
        foreach($listings[1] as $code => $qty) {
            $card = $em->getRepository('NetrunnerdbCardsBundle:Card')->findOneBy(array('code' => $code));
            if($card) $content2[] = array(
                    'title' => $card->getTitle(),
                    'code' => $code,
                    'qty' => $qty
            );
        }
        
        $shared = array();
        foreach($intersect as $code => $qty) {
            $card = $em->getRepository('NetrunnerdbCardsBundle:Card')->findOneBy(array('code' => $code));
            if($card) $shared[] = array(
                    'title' => $card->getTitle(),
                    'code' => $code,
                    'qty' => $qty
            );
        }
        
        
        return $this->render('NetrunnerdbBuilderBundle:Diff:decklistsDiff.html.twig', array(
                'decklist1' => array(
                        'faction_code' => $d1->getFaction()->getCode(),
                        'name' => $d1->getName(),
                        'id' => $d1->getId(),
                        'prettyname' => $d1->getPrettyname(),
                        'content' => $content1
                        ),
                'decklist2' => array(
                        'faction_code' => $d2->getFaction()->getCode(),
                        'name' => $d2->getName(),
                        'id' => $d2->getId(),
                        'prettyname' => $d2->getPrettyname(),
                        'content' => $content2
                        ),
                'shared' => $shared
                )
                );
        
    }
}