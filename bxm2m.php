#!/usr/bin/env php 
<?php
ini_set("memory_limit","100M");
// check load and if > 3, die...

chdir(dirname(__FILE__));

$load = substr(file_get_contents("/proc/loadavg"),0,4);
if ($load > 4) {
    //mail("chregu@bitflux.ch","bxm2m died", "load too high: " . $load);
    die("load too high: " . $load);
} 

$foo = trim(`ps aux | grep bxm2m.php`);

if ($foo && count(split("\n",$foo)) > 5 ) {
//    mail("chregu@bitflux.ch","bxm2m died", "already ". count(split("\n",$foo)) ." processes running");
    die(date("c") . " already ". count(split("\n",$foo)) ." processes running \n");
}
$bxm2mdir = dirname(__FILE__);
$bxm2mcnf = sprintf("%s/conf/bxm2m.conf", $bxm2mdir);

if (!file_exists($bxm2mcnf)) {
    die("bxmail2moblog: Unable to find conffile ".$bxm2mcnf."\n");
}
include_once $bxm2mcnf;

if (!file_exists($conf['bxm2muserfile'])) {
    die("bxmail2moblog: Unable to find userfile ".$conf['bxm2muserfile']."\n");
}

/*
$l = fopen($conf['bxm2mlock'], "a+");
$p = fread($l, 128);
if ($p == "") {
    fwrite($l, posix_getpid());
    fclose($l);
} else {
    fclose($l);
    die("bxmail2moblog: Another Process is already running ...\n");
}
*/

include_once 'Net/POP3.php';
include_once 'Mail/mimeDecode.php';
include_once "XML/RPC.php";

$pop =& new Net_POP3();
if ($pop->connect($conf['bxm2mhost'] , $conf['bxm2mport'] )) {
    if (!$ret = $pop->login($conf['bxm2muser']  ,$conf['bxm2mpass']  )) {
        die("bxmail2moblog: Authentication to mailserver failed ...\n");
    }
    if (PEAR::isError($ret)) {
	print $ret->getMessage() . "\n";
        die("bxmail2moblog: Authentication to mailserver failed ...\n");

}
}
/* text types */
$exttxt = array('plain','html');
/* image types */
$extimg = array('png','gif','jpg','jpeg','octet-stream','applefile');
/* media types */
$extmedia = array('mpeg');


/* command characters */
$cmdchars = array('!',"#");
/* command map */
$cmdmap = array('a' => 'tags', 't' => 'title', 'c' => 'category', 'g' => 'gallery', 'p'=>'password');
/* signature delimiters */
$sigdelim = "#^[\-_]{2,}$#";

$decodeopts = array('include_bodies' => True);
$msgs = $pop->numMsg();
$incoming = array();
if ($msgs && $msgs > 0) {
    for($c=1; $c<=$msgs; $c++) {
        $msg = $pop->getMsg($c);
        $msgdecoder =& new Mail_mimeDecode($msg);
        $msgdecoded = $msgdecoder->decode($decodeopts);
        array_push($incoming, $msgdecoded);
        $pop->deleteMsg($c);
    
    }
}
if (sizeof($incoming) > 0) {
	echo date("c") ." ";
	echo "msgs: ".sizeof($incoming)."\n";
}
$pop->disconnect();
if (sizeof($incoming) > 0) {
    $users = loadUsers($conf['bxm2muserfile']);
    foreach($incoming as $i=>$msg) {
        echo "got mail ...\n";
        $xorigTo=null;
        /* Catch case of multiple x-original-to headers */
        if (isset($msg->headers['x-original-to']) && is_array($msg->headers['x-original-to'])) {
            $xorigTo=$msg->headers['x-original-to'][0];
        } elseif (isset($msg->headers['x-original-to']) && !empty($msg->headers['x-original-to'])) {
            $xorigTo=$msg->headers['x-original-to'];
        }
        $to = split("@", ($xorigTo!=null) ? $xorigTo:$msg->headers['to']);
        $host = array_pop($to); 
        $to = array_shift($to); 
        echo "to: $to@$host\n";
        if (strpos($to, "+") !== False) {
            $to = split("\+", $to);
        } else {
            $to = array($to);
        }
        
        $images = array();
        $media = array();
        $bodyContentType = 'text/plain';
        $userinfo = getUserinfo($users, $to, $host);
        if (is_array($userinfo)) {
		        $body = $category = $title = '';
                if (isset($msg->parts) && $msg->parts != NULL) {
                    foreach($msg->parts as $p=>$part) {
                        echo "part: $p\n";
                        //var_dump($part);
                        $datefolder = date("Y-m");
                        /* multipart/alternative*/
                        if ($part->ctype_primary == 'multipart' && $part->ctype_secondary == 'alternative') {
                            echo "found multipart ".$part->ctype_secondar."...\n";
                            if (isset($part->parts) && is_array($part->parts)) {
                                echo "mp parts ok \n";
                                $p = null;
                                foreach($part->parts as $i=>$multipart) {
                                    if ($multipart->ctype_secondary == 'plain' && ($p==null)) {
                                        $p = $multipart;
                                    } elseif ($multipart->ctype_secondary == 'html') {
                                        $p = $multipart;
                                    }
                                    
                                }
                                
                                if ($p && isset($p->body)) {
                                    preg_match("#<body.[^>]*>(.*)</body>#is", $p->body, $matches);
                                    if (isset($matches[1]) && !empty($matches[1])) {
                                        $bodystr = $matches[1];
                                    } else {
                                        $bodystr = $p->body;
                                    }
                                    /*echo "mp body ....";
                                    var_dump($bodystr);*/
                                    $body = decodeBody($bodystr, $p->headers['content-transfer-encoding'], $p->ctype_parameters['charset']);
                                    /*var_dump($body);*/
                                    $bodyContentType = $p->ctype_primary."/".$p->ctype_secondary;
                                    echo "bodyContentType: $bodyContentType\n";
                                }
                            }
                        } 
                        /* text/plain text/html */
                        elseif (in_array(strtolower($part->ctype_secondary), $exttxt)) {
                           echo "ttt:  ".$part->ctype_primary."/".$part->ctype_secondary."\n";
                           $body = trim(decodeBody($part->body,$part->headers['content-transfer-encoding'],$part->ctype_parameters['charset']));
                        } 
                        /* images */
                        elseif (in_array(strtolower($part->ctype_secondary), $extimg)) {
                            switch (strtolower($part->headers['content-transfer-encoding'])) {
                                case 'quoted-printable':
                                $imagebody = imap_utf8($part->body);
                                break;
                                case 'base64':
                                $imagebody = base64_decode($part->body);
                                break;
                                default: 
                                $imagebody = $part->body;
                            }
                            
                            if (isset($part->d_parameters) && isset($part->d_parameters['filename'])) {
                                $part->ctype_parameters['name'] = $part->d_parameters['filename'];
                            }
                            $imagename = date("dHis-").makeUri($part->ctype_parameters['name'],true);
                            if ($userinfo['imgpath'] != "") {
                                if ($userinfo['datefolder'] != 'false') {
                                    $userinfo['imgpath'] = $userinfo['imgpath'] . "/".$datefolder;
                                }
                                $fname = sprintf("%s/%s", $userinfo['imgpath'], $imagename);
                                
                            } else {
                                $fname = $imagename;
                            }
                            
                            //autorotate
                            
                            $tmpname = tempnam("/tmp/","jhead");
                            file_put_contents($tmpname,$imagebody);
                            exec("jhead -autorot $tmpname");
                            
                            $imagebody = file_get_contents($tmpname);
                            unlink($tmpname);
                            
                            array_push($images, array('filename' =>  $fname,
                            'dynurl'      => sprintf("%s/dynimages/%s/files/%s/%s", $userinfo['bloghost'], $userinfo['imgsize'], $userinfo['imgpath'],$imagename),
                            'url'      => sprintf("%s/files/%s/%s", $userinfo['bloghost'], $userinfo['imgpath'],$imagename),
                            'body'     => $imagebody));
                        }
                        /* media like mp3 */
                        elseif (in_array(strtolower($part->ctype_secondary), $extmedia)) {
                            switch (strtolower($part->headers['content-transfer-encoding'])) {
                                case 'quoted-printable':
                                $imagebody = imap_utf8($part->body);
                                break;
                                case 'base64':
                                $imagebody = base64_decode($part->body);
                                break;
                                default: 
                                $imagebody = $part->body;
                            }
                            
                            if (isset($part->d_parameters) && isset($part->d_parameters['filename'])) {
                                $part->ctype_parameters['name'] = $part->d_parameters['filename'];
                            }
                            $filename = makeUri($part->ctype_parameters['name'],true);
                            $imagename = date("dHis-").$filename;
                            if ($userinfo['imgpath'] != "") {
                                if ($userinfo['datefolder'] != 'false') {
                                    $userinfo['imgpath'] = $userinfo['imgpath'] . "/".$datefolder;
                                }
                                $fname = sprintf("%s/%s", $userinfo['imgpath'], $imagename);
                                
                            } else {
                                $fname = $imagename;
                            }
                            
                            array_push($media, array('filename' =>  $fname,
                            'url'      => sprintf("%s/files/%s/%s", $userinfo['bloghost'], $userinfo['imgpath'],$imagename),
                            'orifilename' => $filename,
                            'body'     => $imagebody));
                        } elseif (strtolower($part->ctype_secondary) == 'vnd.nokia.landmarkcollection+xml') {
                            switch (strtolower($part->headers['content-transfer-encoding'])) {
                                case 'quoted-printable':
                                $xml = imap_utf8($part->body);
                                break;
                                case 'base64':
                                $xml = base64_decode($part->body);
                                break;
                                default: 
                                $xml = $part->body;
                            }
                            $dom = domdocument::loadXML($xml);
                            $xp = new domxpath($dom);
                            $tags = '';
                            
                            $res = $xp->query('/lm:lmx/lm:landmarkCollection/lm:landmark/lm:coordinates/lm:latitude/text()');
                            if ($res->length > 0) {
                                $lat = $res->item(0)->nodeValue;
                                $tags .= 'geo:lat='.$lat;
                            }
                            $res = $xp->query('/lm:lmx/lm:landmarkCollection/lm:landmark/lm:coordinates/lm:longitude/text()');
                            if ($res->length > 0) {
                                $lon = $res->item(0)->nodeValue;
                                $tags .= ' geo:lon='.$lon;
                            }
                            $loc = '';
                            $res = $xp->query('/lm:lmx/lm:landmarkCollection/lm:landmark/lm:addressInfo/lm:city/text()');
                            if ($res->length > 0) {
                                $loc .= $res->item(0)->nodeValue;
                            } 
                            
                            $res = $xp->query('/lm:lmx/lm:landmarkCollection/lm:landmark/lm:name/text()');
                            if ($res->length > 0) {
                                if ($loc && trim($loc) != trim($res->item(0)->nodeValue)) {
                                    $loc .= ' ' . $res->item(0)->nodeValue;
                                } else {
                                    $loc = $res->item(0)->nodeValue;
                                }
                            }
                            
                            if ($loc) {
                                
                                $tags .= ' "geo:loc='.$loc.'"';
                            }
                            
                        }
                        
                    }
                
            } else {
                echo "default body \n";
                $body = decodeBody($msg->body,$msg->headers['content-transfer-encoding'],$msg->ctype_parameters['charset']);
                     
                
            }
             
            /* stop if picturesonly="yes" and no images were found */
            if (isset($userinfo['picturesonly']) && $userinfo['picturesonly']=='true'
                && sizeof($images) == 0) {
                continue;
            }
             
            /* find category,title etc. (yet doesn't works with text/html) */
            if ($contentBodyType != "text/html") {
                echo "split bodylines etc.\n";
                if (strpos($body, "\n") != FALSE) {
                    $bodylines = split("\n", $body);
                } else {
                    $bodylines = array($body);
                }
	        	
                $bodylen = count($bodylines);
                $cmds = array();
                foreach($bodylines as $i=>$line) {
                    if (preg_match( $sigdelim, trim($line))) {
                        $bodylines = array_slice($bodylines,0,$i );
                        break;
                    }
                    //flickr tags style
                    if (substr(strtolower($line),0,5) == 'tags:') {
                        $tags =  trim(substr($line, 6));
                        unset($bodylines[$i]);
                    }
                    
                    if (in_array(substr($line,0,1), $cmdchars)) {
                        $cmdc = substr($line, 1, 1);
                        $cmdv = trim(substr($line, 2));
                        ${$cmdmap[$cmdc]} = $cmdv;
                        unset($bodylines[$i]);
                    }
                    
                    
                }
            
                if (strpos($msg->headers['content-type'],"text/html") !== 0) {
                    $body = nl2br(implode("\n", $bodylines));
                }
            }
            
            echo "final body ...\n";
             
            
            if ($category == "") {
                $category = $userinfo['defaultcat'];
            }
               
            if ($title == "") {
                $title = imap_utf8($msg->headers['subject']);
                $firstline = trim(array_shift($bodylines));
                if (strpos(trim($title),$firstline) === 0 || preg_match('#^DSC[0-9]{5}$#',$title))  {
                    $title = $firstline;
                    $body = preg_replace("#^".$firstline."#","",trim($body));
                    $body = preg_replace("#^\s*<br />#","",trim($body));
                }
                
            }
            $title = str_replace('[possible SPAM]','',$title);
   
            if ((!isset($to[1])||empty($to[1])) && isset($password)) {
                $to[1] = $password; 
            } elseif ((!isset($to[1])||empty($to[1])) && isset($userinfo['defaultpass'])) {
                $to[1] = $userinfo['defaultpass'];
            } else {
                //$to[1] = '';
            }
            /* rpc client */
            $rpc = new XML_RPC_Client($userinfo['path'], $userinfo['bloghost'], $userinfo['port']);
            //$rpc->setDebug(true);
            if (isset($userinfo['httpuser']) && isset($userinfo['httppass'])) {
		        $rpc->setCredentials($userinfo['httpuser'], $userinfo['httppass']);
		    }
		    $rpcuser = new XML_RPC_Value($userinfo['bloguser'], 'string');
            $rpcpass = new XML_RPC_Value($to[1], 'string');
                /* prepend image tags containing moblogged images to body */
                
                if (isset($images)) {
                    $mime = null;
                    if (!empty($userinfo['flickr'])) {
                        include_once('Mail.php');
                        include_once('Mail/mime.php');
                        $text = $body;
                        if ($tags) {
                            $text = "tags: $tags\n$text";
                        }
                        $crlf = "\n";
                        $hdrs = array(
                            'From'    => 'moblogger@liip.ch',
                            'Subject' => utf8_decode($title),
                         );
                         $mime = new Mail_mime($crlf);
                         
                         $mime->setTXTBody(utf8_decode($text));
                         
                         
                    }
            
                    $imgbody = "";
                    $gtags = parse_geotags($tags);
                    foreach($images as $i => $image) {
                        if (isset($gtags['lon']) && isset($gtags['lat']))  {
                            $image['body'] = exif_setGPSCoord($image['body'], $gtags);
                        }
                        $imgbody.= sprintf("<a href=\"http://%s\"><img src=\"http://%s\" border=\"0\"/></a>", $image['url'], $image['dynurl']);
                        $imgbody.= "<br/><br/>"; 
                        $params = array(new XML_RPC_Value('1', 'string'),$rpcuser,$rpcpass,
                                        new XML_RPC_Value(array('bits' => new XML_RPC_Value($image['body'],'base64'),
                                                                'name' => new XML_RPC_Value($image['filename'],'string')
                                                                ),
                                                          'struct')
                                        );
                        $msg = new XML_RPC_Message("metaWeblog.newMediaObject", $params);
                        $response = $rpc->send($msg);
                        if ($mime) {
                            $mime->addAttachment($image['body'],'image/jpeg',$image['filename'],false);
                        }
                    }
                   
                 $imgbody = utf8_encode($imgbody); 
                 $body = $imgbody.$body;
            }
            
             if (isset($media)) {
                    $mediabody = "";
                    foreach($media as $i => $m) {
                        $mediabody.= sprintf("<a href=\"http://%s\">%s</a>", $m['url'], $m['orifilename']);
                        $mediabody.= "<br/><br/>"; 
                        $params = array(new XML_RPC_Value('1', 'string'),$rpcuser,$rpcpass,
                                        new XML_RPC_Value(array('bits' => new XML_RPC_Value($m['body'],'base64'),
                                                                'name' => new XML_RPC_Value($m['filename'],'string')
                                                                ),
                                                          'struct')
                                        );
                        $msg = new XML_RPC_Message("metaWeblog.newMediaObject", $params);
                        $response = $rpc->send($msg);
                    }
                 $mediabody = utf8_encode($mediabody); 
                 $body = $mediabody.$body;
                 }
            
              
            $body = str_replace($userinfo['bloguser']."+".$to[1], "xxxxxx", $body);
            echo "prependfrom: ".$userinfo['prependfrom']."\n"; 
            if (isset($userinfo['prependfrom']) and $userinfo['prependfrom']=='true') {
                preg_match("#(.*)<.+@.+>#", $msg->headers['from'], $matches);
                if (isset($matches[1])) {
                    $body = "Via: ".trim($matches[1])."<br/><br/>".$body;
                }
            }

            /* request for new post*/
            $params = array(new XML_RPC_Value('1', 'string'),
                            new XML_RPC_Value($userinfo['bloguser'], 'string'),
                            new XML_RPC_Value($to[1], 'string'),
                            new XML_RPC_Value(array(
                                'title' => new XML_RPC_Value($title,'string'),
                                'description' => new XML_RPC_Value($body, 'string'),
                                 'mt_keywords' => new XML_RPC_Value($tags, 'string')
                            ), 'struct'));
            $msg = new XML_RPC_Message("metaWeblog.newPost", $params);
            $response = $rpc->send($msg);
/*            echo "rpc response: ";
            var_dump($response);*/
            if ($response!=0 && is_array($response->value()->me)) {
                $postid = array_pop($response->value()->me);
            }                                             
            
            /* requests for category and images */
            if ($postid && $postid != "") {
                // if posted and mime for flickr is set... 
                 if ($mime) {
                       //do not ever try to call these lines in reverse order
                         $body = $mime->get();
                         $hdrs = $mime->headers($hdrs);
                         
                         $mail =& Mail::factory('mail');
                         $mail->send($userinfo['flickr'], $hdrs, $body);
                 } 
                    
                    
                $params = array(new XML_RPC_Value($postid, 'string'),
                                new XML_RPC_Value($userinfo['bloguser'], 'string'),
                                new XML_RPC_Value($to[1], 'string'),
                                new XML_RPC_Value(array(
                                                    new XML_RPC_Value(array('categoryName' => new XML_RPC_Value($category,'string')),'struct')
                                                    ), 'array')
                               );
                $msg = new XML_RPC_Message("mt.setPostCategories", $params);
                $response = $rpc->send($msg);
		        if (is_array($response->value()->me)) {
                    $postid = array_pop($response->value()->me);
                }                                             
		
                /* requests for category and images */
                if ($postid && $postid != "") {
                    $params = array(new XML_RPC_Value($postid, 'string'),
                                    new XML_RPC_Value($userinfo['bloguser'], 'string'),
                                    new XML_RPC_Value($to[1], 'string'),
                                    new XML_RPC_Value(array(
                                                        new XML_RPC_Value(array('categoryName' => new XML_RPC_Value($category,'string')),'struct')
                                                        ), 'array')
                                   );
                    $msg = new XML_RPC_Message("mt.setPostCategories", $params);
                    $response = $rpc->send($msg);
                }
                
                echo "processed blogpostid:$postid ($title) ".$userinfo['bloghost']."\n";
            }
        }
    }
}                                                                                         

if (file_exists($conf['bxm2mlock'])) {
    unlink($conf['bxm2mlock']);
}
exit;

/* functions */
function loadUsers($f) {
    $users = array();
    if (file_exists($f)) {
        $xml = simplexml_load_string(file_get_contents($f));
        foreach($xml->user as $user) {
            $attr = array();
            foreach ($user->attributes() as $name=>$value) {
                if ($value) $attr[$name] = (string) $value;
            }
            if (!isset($attr['emailhost'])) {
                if (isset($attr['host'])) {
                    $attr['emailhost'] = $attr['host'];
                }
            }
            
            $users[$attr['id']] = $attr;
        }
    }
    return $users;
}

function getUserinfo($users, $to, $host) {
   foreach($users as $id=>$prms) {
       if (isset($prms['type']) && $prms['type']=='regex') {
           preg_match($prms['emailhost'], trim($host), $matches);
           if (isset($matches[0]) && sizeof($matches) > 0) {
               if (!isset($prms['bloghost']) || $prms['bloghost'] == "" ) {
                   $prms['bloghost'] = $matches[0];
               }
               if ($prms['bloguser']=="") {
                   $prms['bloguser']=$to[0];
               }
               
               checkSubIsUser($prms);     
               return $prms;
           }
       } else if ($prms['emailhost'] == trim($host) && $prms['emailuser'] == $to[0]) {
           if (!isset($prms['bloghost']) || $prms['bloghost'] == "" ) {
                   $prms['bloghost'] = $prms['emailhost'];
           }
           if ($prms['bloguser']=="") {
                   $prms['bloguser']=$to[0];
           }
           
           checkSubIsUser($prms);
           return $prms;
       
       } else if ($prms['emailhost'] == trim($host) && !$prms['emailuser']) {
            if (!isset($prms['bloghost']) || $prms['bloghost'] == "" ) {
                   $prms['bloghost'] = $prms['emailhost'];
           }
           if ($prms['bloguser']=="") {
                $prms['bloguser']=$to[0];
           }
           
           checkSubIsUser($prms);
           return $prms;
       
       }
   
       
   }
   return false;    
}

function checkSubIsUser(&$prms) {
    if (isset($prms['subisuser']) && $prms['subisuser']=='yes') {
        $prms['bloghost'] = $prms['bloguser'].".".$prms['bloghost'];
    }
    return;
}



//from bx_helpers_string ;)

    function makeUri ($title,$preserveDots = false) {
        $title = html_entity_decode($title,ENT_QUOTES,'UTF-8');
        
        $title = trim($title);
        if (!$title) {
            $title = "none";   
        }
        
        
        $newValue= strtolower(preg_replace("/[-;: ~!,?+'\$£\"*ç%&\/\(\)=]/u","-",$title));
        if (!$preserveDots) {
            $newValue= str_replace(".","-",$newValue);
        }
        $newValue= preg_replace("/-{2,}/u","-",$newValue);
        $newValue= preg_replace("/[öÖ]/u","oe",$newValue);
        $newValue= preg_replace("/[üÜ]/u","ue",$newValue);
        $newValue= preg_replace("/[äÄ]/u","ae",$newValue);
        $newValue= preg_replace("/[éè]/u","e",$newValue);
        $newValue= preg_replace("/[Ïï]/u","i",$newValue);
        $newValue= preg_replace("/[ñ]/u","n",$newValue);
        $newValue= preg_replace("/[à]/u","a",$newValue);
        
        $newValue= preg_replace("/[\n\r]*/u","",$newValue);
        $newValue= preg_replace("/—+$/u","",$newValue);
        $newValue= preg_replace("/^-/u","",$newValue);
        if (!$preserveDots) {
            $newValue= preg_replace("/_([0-9]+)$/u","-$1",$newValue);
        } else {
            $newValue= preg_replace("/_([0-9]+)\./u","-$1.",$newValue);
        }
        $newValue = trim($newValue,"-");
        return $newValue;
    }
    
    function decodeBody($body,$transferEncoding ,$charset = 'utf-8') {
        switch (strtolower($transferEncoding)) {
            case 'quoted-printable':
            $body = trim(quoted_printable_decode($body));
            break;
            case 'base64':
            $body = trim(base64_decode($body));
            break;
            default: 
            $body = trim($body);
        }   

            if ($charset != 'utf-8') {
                
                $body = iconv ($charset,'UTF-8//IGNORE',$body);
            }
        return $body;
        
    }
    
    
function exif_setGPSCoord($data,$coord) {
    require_once(dirname(__FILE__) . '/pel/PelDataWindow.php');
     require_once(dirname(__FILE__) . '/pel/PelJpeg.php');
     require_once(dirname(__FILE__) . '/pel/PelTiff.php');
     
    $data = new PelDataWindow($data);
    
    if (PelJpeg::isValid($data)) {
        $jpeg = new PelJpeg();
        $jpeg->load($data);
        $exif = $jpeg->getExif();
        if (!$exif) {
            $exif = new PelExif();
            $jpeg->setExif($exif);
            $tiff = new PelTiff();
            $exif->setTiff($tiff);
        } 
        
        
        $tiff = $exif->getTiff();
        
    } else {
        return $data;
    }
    $ifd0 = $tiff->getIfd();
    
    if ($ifd0 == null) {
        /* No IFD in the TIFF data?  This probably means that the image
        * didn't have any Exif information to start with, and so an empty
        * PelTiff object was inserted by the code above.  But this is no
        * problem, we just create and inserts an empty PelIfd object. */
        $ifd0 = new PelIfd(PelIfd::IFD0);
        $tiff->setIfd($ifd0);
    }
    
    
    $g = $ifd0->getSubIfd(PelIfd::GPS);
    
    if (!$g) {
        $g = new PelIfd(3);
        $ifd0->addSubIfd($g);
    }
    
    exif_writeGpsCoord($g, $coord);
    
    return $jpeg->getBytes();
    
    
}


function exif_writeGpsCoord($g,$coord) {
    if (isset ($coord['lat'])) {
        $lat = $coord['lat'];
        $c = exif_buildRationalValue($coord['lat']);
        $e1 =  $g->getEntry(PelTag::GPS_LATITUDE_REF);
        $e2 =  $g->getEntry(PelTag::GPS_LATITUDE);
        if (!$e2) {
            $e1 = new PelEntryAscii(PelTag::GPS_LATITUDE_REF);
            $g->addEntry($e1);
            $e2 = new PelEntryRational(PelTag::GPS_LATITUDE);
            $g->addEntry($e2);
        }
        if ($lat >= 0) {
            $e1->setValue("N");
        } else {
            $e1->setValue("S");
        }
        $e2->setValue($c[0],$c[1],$c[2]);
    }
    if (isset ($coord['lon'])) {
        $lon = $coord['lon'];
        $c = exif_buildRationalValue($coord['lon']);
        $e1 =  $g->getEntry(PelTag::GPS_LONGITUDE_REF);
        $e2 =  $g->getEntry(PelTag::GPS_LONGITUDE);
        if (!$e2) {
            $e1 = new PelEntryAscii(PelTag::GPS_LONGITUDE_REF);
            $g->addEntry($e1);
            $e2 = new PelEntryRational(PelTag::GPS_LONGITUDE);
            $g->addEntry($e2);
        }
        if ($lon >= 0) {
            $e1->setValue("E");
        } else {
            $e1->setValue("W");
        }
        $e2->setValue($c[0],$c[1],$c[2]);
    }
    
}

function exif_buildRationalValue($coord) {
    $coord = abs($coord);
    $before = array((floor($coord)),1);
    $after = array(floor(($coord - floor($coord)) * 6000), 100);
    return array($before,$after,array(1,0));
}

function parse_geotags($tags) {
    $tags = explode(" ",$tags);
    $ret = array();
    foreach($tags as $key => $tag) {
            $name = null;
            if (strpos($tag,"=")) {
                list($name,$value) = split("=",$tag);   
            } else if (strpos($tag,":") !== false) {
                list($name,$value) = split(":",$tag);
            }
            if ($name) {
                switch ($name) {
                    case "geo:long":
                    case "geo:lon":
                    case "lo":
                    case "long":
                    case "lon":
                        $ret['lon'] = $value;
                        break;
                    case "geo:lat":
                    case "la":
                    case "lat":
                        $ret['lat'] = $value;
                        break;
                    case "plaze":
                    case "loc":
                    case "geo:loc":
                    case "location":
                        $ret['loc'] = $value;
                        break;
                }
            }
        }
      return $ret; 
      
        
}
    
    
    ?>
