<?php
/*******************************************************************************
  *  PHP-CLASSES
  *                               includes imagemanipulation-class to autorotate
  *                               e.g. iPhone images
  *
  *                               to enable autoRotation extend the $config-Array
  *                               with var: $autorotate_pattern
  *
  *                               e.g.: 'autorotate_pattern' => '/^iPhone.*$/i'
  *
  *  @php_version -   5.2.x
  * ---------------------------------------------------------------------------
  *  @version     -   v1.1
  *  @date        -   $Date: 2013/03/07 23:52:24 $
  *  @author      -   Horst Nogajski <coding AT nogajski DOT de>
  *  @licence     -   GNU GPL v2 - http://www.gnu.org/licenses/gpl-2.0.html
  * ---------------------------------------------------------------------------
  *  $Source: /WEB/pw_pop3/EmailImageAdaptor.php,v $
  *  $Id: EmailImageAdaptor.php,v 1.1.2.9 2013/03/07 23:52:24 horst Exp $
  ******************************************************************************
  *
  *  LAST CHANGES:
  *
  *  2013-02-23    change  	RC1   no more using stream_wrapper for fetching messages, instead we use RetrieveMessage( $msg_id, $headers, $body, -1 ) now
  *                               - so we have only one single-connection to the server, maybe that can solve the issue with Ryans double fetching Mails ?
  *
  *  2013-02-26    change  	RC2   now we pull all images from Email, function getImages($path) now returns filename/s as array:
  *                               - array('filenames' => array('file1.jpg','file2.jpg','file3.jpg'), 'subject' => 'another subject line', 'body' => 'optional some descriptive Text')
  *                               - array('filenames' => array('file.jpg'), 'subject' => 'a subject line', 'body' => '')
  *
  *  2013-02-28    change  	1.0   now use strpos and substr to search for BodyText and BodyPassword (instead of preg_match)
  *
  *  2013-03-06    new      1.1   added a class to autoRotate iPhone-Images !!!  ;-)
  *
**/


require_once( dirname(__FILE__) . '/pop3_classes/mime_parser.php' );
require_once( dirname(__FILE__) . '/pop3_classes/rfc822_addresses.php' );
require_once( dirname(__FILE__) . '/pop3_classes/pop3.php' );
//require_once( dirname(__FILE__) . '/pop3_classes/sasl.php' );



class EmailImageAdaptor {

	private $pw_pop3 = null;

	public function __construct(array $config) {
		// Set all settings related to the email account
		$this->pw_pop3 = new hnpw_pop3($config);
	}

	public function __destruct() {
		$this->pw_pop3->close();
		unset($this->pw_pop3);
	}

	public function testConnection() {
		// Tests that the email settings work. This would be used by the module at
		// config time to give the user a Yes or No as to whether their email settings
		// are functional. Returns a boolean TRUE or FALSE.
        return $this->pw_pop3->test_connection();
	}

	public function getImages($path) {
		// Connects to pop3 server, grabs queued messages/images, saves them
		// in the directory specified by $path, then deletes them from the POP3.
		// Returns an array of the image filenames that were saved.
		// Ideally the filenames are the same that they were in the email.
		// If fatal error occurred or nothing new, returns blank array.
		// Proposed return array would be:
		// array(
		//   0 => array('filenames' => array('file1.jpg'), 'subject' => 'subject line text', 'body' => 'maybe some description to the image, but is optional'),
		//   1 => array('filenames' => array('file1.jpg','file2.jpg','file3.jpg'), 'subject' => 'another subject line', 'body' => ''),
		//   ...and more items as found...
		//   );

		if( ! $this->pw_pop3->connect() )
		{
			return array();
		}
		if( ! $this->pw_pop3->has_new_msg() )
		{
			return array();
		}
		$aImageEmails = array();
		while( $this->pw_pop3->has_new_msg() )
		{
			@set_time_limit(120);
			$aResult = $this->pw_pop3->process_next_msg($path);
			if( ! is_array($aResult) )
			{
				continue;
			}
			$aImageEmails[] = $aResult;
		}
		$this->pw_pop3->close();

		return $aImageEmails;
	}

	public function getErrors() {
		// Returns an array of error messages, if they occurred.
		// Returns blank array if no errors occurred.
		// The module would call this after getImages() or testConnection() to
		// see if it should display/log any error messages.
        return (array)$this->pw_pop3->get_errors();
	}

}





class hnpw_pop3
{
    private $autorotate_pattern             = null;                  /* a RegEx-Pattern that will compared against
                                                                        the Make-Model-Exiftag, if matches, image
                                                                        gets rotated automatically                  */

	private $debug                          = 0;                     /* Output debug information                    */
	private $html_debug                     = 0;                     /* Debug information is in HTML                */

	private $hostname                       = '';                    /* POP 3 server host name                      */
	private $port                           = 110;                   /* POP 3 server host port,
	                                                                    usually 110 but some servers use other ports
	                                                                    Gmail uses 995                              */
	private $tls                            = 0;                     /* Establish secure connections using TLS      */
	private $realm                          = '';                    /* Authentication realm or domain              */
	private $workstation                    = '';                    /* Workstation for NTLM authentication         */
	private $authentication_mechanism       = 'USER';                /* SASL authentication mechanism               */
	private $join_continuation_header_lines = 1;                     /* Concatenate headers split in multiple lines */

	private $user                           = '';                    /* Authentication user name                    */
	private $password                       = '';                    /* Authentication password                     */
	private $apop                           = 0;                     /* Use APOP authentication                     */
	private $valid_senders                  = array();               /* SenderEmailAddress wich is allowed to post  */
	private $body_password                  = '';                    /* more security: password in Mailbody         */
	private $body_txt_start                 = '';                    /* */
	private $body_txt_end                   = '';                    /* */

	private $max_allowed_email_size         = 31457280;              /* 30 MB (1024 * 1024 * 30) */

	private $aValidVars                     = null;
	private $pop3                           = null;
	private $connected                      = null;
	private $new_msg                        = null;

	private $total_list                     = null;
	private	$total_messages                 = null;
	private	$total_size                     = null;
	private $pulled_image_counter           = 1;                     /* starts with 1 */

	private $errors                         = array();


	public function test_connection()
	{
		if( ( $this->errors[] = $this->pop3->Open() ) != '' )
		{
			return false;
		}
        if( ( $this->errors[] = $this->pop3->Login( $this->user, $this->password, $this->apop ) ) != '' )
        {
			return false;
        }

		$this->connected = true;
		$this->total_messages = null;
		$this->total_size = null;
		if( ( $this->errors[] = $this->pop3->Statistics( $this->total_messages, $this->total_size ) ) != '' )
		{
			$this->total_messages = null;
			$this->total_size = null;
			$this->close();
			return false;
		}
		$this->total_messages = null;
		$this->total_size = null;
		$this->close();
		return true;
	}


	public function connect()
	{
		if( ( $this->errors[] = $this->pop3->Open() ) != '' )
		{
			$this->connected = false;
			return false;
		}
        if( ( $this->errors[] = $this->pop3->Login( $this->user, $this->password, $this->apop ) ) != '' )
        {
			$this->connected = false;
			return false;
        }
		$this->connected = true;

		$this->total_messages = null;
		$this->total_size = null;
		if( ( $this->errors[] = $this->pop3->Statistics( $this->total_messages, $this->total_size ) ) != '' )
		{
			$this->connected = false;
			return false;
		}

        if( is_null($this->total_messages) || intval($this->total_messages)<1 )
        {
			$this->new_msg = false;
			return true;
        }

        # aList
		$aList = $this->pop3->ListMessages( '', 0 );
		if( ! is_array($aList) )
		{
			$this->new_msg = false;
			return true;
		}
        $this->total_list = array();
		foreach( $aList as $k=>$size )
		{
			$this->total_list[] = array($size,$k);
		}

		$this->new_msg = count($this->total_list)>0 ? true : false;
        return true;
	}


	public function close()
	{
		if( ! $this->connected )
		{
			return null;
		}
		if( $this->pop3->close() != '' )
		{
			return false;
		}
		$this->connected = false;
		return true;
	}


	public function get_errors()
	{
		$a = array();
		foreach( $this->errors as $e )
		{
			if($e=='')
			{
				continue;
			}
			$a[] = $e;
		}
		$this->errors = $a;
		return $this->errors;
	}


	public function has_new_msg()
	{
		return $this->new_msg===true ? true : false;
	}


	public function process_next_msg($path)
	{
		if( ! $this->new_msg )
		{
			return false;
		}
		# get id of next unprocessed mail
		$next = array_shift($this->total_list);
        $this->new_msg = count($this->total_list)>0 ? true : false;

        if( $next[0]>$this->max_allowed_email_size )
        {
			$this->errors[] = 'Email processing rejected! max_allowed_email_size exeeded: ' . strval($next[0] .' > '. $this->max_allowed_email_size);
			return false;
        }

        $res = $this->get_message( $next[1], $path );
		if( ! is_array($res) || ! is_array($res['filenames']) )
		{
			# error is already set in method get_message()
        	$this->errors[] = $this->pop3->DeleteMessage( $next[1] );
			return false;
		}

		# we have valid data :-)
		# delete Email on PopServer
        if(($this->errors[] = $this->pop3->DeleteMessage( $next[1] )) != "" )
		{
			return false;
		}

		# pass valid data
		return $res;
	}




	private function get_message( $msg_id, $path )
	{
		$body = null;
		$headers = null;
		if( ( $error = $this->pop3->RetrieveMessage( $msg_id, $headers, $body, -1 ) ) != "" )
		{
			$this->errors[] = $error;
			return false;
		}

        $message_file = implode("\r\n",$headers) ."\r\n". implode("\r\n",$body);  // ???

		$mime = new mime_parser_class();
		$mime->decode_bodies = 1;
		$parameters = array( 'Data'=>$message_file, 'SkipBody'=>0 );
		if( ! $mime->Decode( $parameters, $decoded ) )
		{
			$this->errors[] = 'Unknown error when trying to decode MimeMessage';
			return false;
		}

		if( ! isset($decoded['0']['ExtractedAddresses']['from:'][0]['address']) || ! isset($decoded['0']['Headers']['subject:']) || ! isset($decoded['0']['Parts']) || ! is_array($decoded['0']['Parts']) || count($decoded['0']['Parts'])<1 )
		{
			$this->errors[] = 'Missing sections in decoded MimeMessage';
			return false;
		}

		$from = strtolower($decoded['0']['ExtractedAddresses']['from:'][0]['address']);
		if( ! in_array( $from, $this->valid_senders ) )
		{
			$this->errors[] = 'Email processing rejected! Invalid sender: ' . $from;
			return false;
		}
		$subject = $decoded['0']['Headers']['subject:'];

		$html = array();
		$plain = array();
		$image = array();
		$i = -1;
		// loop through all MessageParts and collect all Data (without image data)
		foreach( $decoded['0']['Parts'] as $main_part )
		{
			$i++;
			if( count($main_part['Parts'])>0 )
			{
				$n = -1;
				foreach( $main_part['Parts'] as $sub_part )
				{
					$n++;
					$this->get_message_part( "$i-$n", $sub_part, $html, $plain, $image, false );
				}
			}
			else
			{
				$this->get_message_part( "$i", $main_part, $html, $plain, $image, false );
			}
		}

        // optional checking extra password
		if( is_string($this->body_password) && strlen(trim($this->body_password))>0 )
		{
			$pass = false;
			# check for extra security password
			foreach( array_merge($plain,$html) as $k=>$v )
			{
				if( false !== strpos($v['Body'], trim($this->body_password)) )
				{
					$pass = true;
					break;
				}
			}
			if( $pass!==true )
			{
				$this->errors[] = 'Email processing rejected! Required BodyPassword not found in Email: ' . $subject;
				return false;
			}
		}

		// optional checking and extracting BodyText
		$BodyText = '';
		if( is_string($this->body_txt_start) && strlen(trim($this->body_txt_start))>0 && is_string($this->body_txt_end) && strlen(trim($this->body_txt_end))>0 )
		{
			foreach( array_merge($plain,$html) as $k=>$v )
			{
				if( false !== ($pos1 = strpos($v['Body'], trim($this->body_txt_start))) )
				{
					$pos1 += strlen(trim($this->body_txt_start));
					if( false !== ($pos2 = strpos($v['Body'], trim($this->body_txt_end), $pos1)) )
					{
						$BodyText = substr($v['Body'], $pos1, ($pos2-$pos1));
						break;
					}
				}
			}
		}

		// check for image
		if( count($image)==0 )
		{
			$this->errors[] = 'Email processing rejected! No image found in Email: ' . $subject;
			return false;
		}
		// safe all imgdata to filesystem
		$n = 0;
		$filenames = array();
		foreach( $image as $img )
		{
//			$img['size'];
//			$img['BodyPartId'];
//			$img['imgname'];
//			$img['imgextension'];

			$p = explode('-',$img['BodyPartId']);
			if( count($p)==2 )
			{
				$imgdata =& $decoded['0']['Parts'][$p[0]]['Parts'][$p[1]]['Body'];
			}
			else
			{
				$imgdata =& $decoded['0']['Parts'][$p[0]]['Body'];
			}

			$img_basename = is_string($img['imgname']) ? $img['imgname'] : 'imgfile_'. strval($this->pulled_image_counter++) .'.'. $img['imgextension'];
			$img_filename = $path .'/'. $img_basename;
			$this->next_free_imgname($img_filename);
			$file_saved = file_put_contents( $img_filename, $imgdata, LOCK_EX );
			if( $file_saved===strlen($imgdata) )
			{
				$filenames[] = basename($img_filename);
			}
			$imgdata = null;
		}
		$filenames = is_array($filenames) && count($filenames)>0 ? $filenames : null;




// >>>>>>>
       		if( is_string($this->autorotate_pattern) && ! is_null($filenames) && function_exists('exif_read_data') )
			{
				foreach($filenames as $fn)
				{
					$fn = $path .'/'. $fn;
					$exif = @exif_read_data( $fn, 'IFD0');
					if( ! is_array($exif) || ! isset($exif['Model']) || preg_match($this->autorotate_pattern,$exif['Model'])!==1 )
					{
						continue;
					}
			        hn_ImageManipulation::jpegfile_auto_correction($fn);
				}
			}
// <<<<<<<




		return array('filenames'=>$filenames, 'subject'=>$subject, 'body'=>$BodyText);
	}


	private function get_message_part( $BodyPartId, &$p, &$html, &$plain, &$image, $withimgdata=false )
	{
		if( ! isset($p['Headers']['content-type:']) )
		{
			return;
		}
		$type = null;
		$aData = array();
		$aData['BodyPartId'] = $BodyPartId;
        if( preg_match( '#^image/(jpeg|png|gif).*$#', $p['Headers']['content-type:'] )===1 )
        {
        	$type = 'image';
        }
        elseif( preg_match( '#^text/plain.*$#', $p['Headers']['content-type:'] )===1 )
        {
			$type = 'plain';
        }
        elseif( preg_match( '#^text/html.*$#', $p['Headers']['content-type:'] )===1 )
        {
			$type = 'html';
        }
        else
        {
			return;
        }
		$aData['size'] = $p['BodyLength'];

		if( $type!='image' )
		{
			$aData['Body'] = $p['Body'];
		}
		else
		{
			$aData['Body'] = $withimgdata===true ? $p['Body'] : null;
			if( isset($p['FileName']) && strlen($p['FileName'])>0 )
			{
				$aData['imgname'] = $p['FileName'];
			}
			elseif( isset($p['Headers']['content-transfer-disposition:']) && strlen($p['Headers']['content-transfer-disposition:'])>0 && preg_match('/.*?name=(.*?\....).*/i', $p['Headers']['content-transfer-disposition:'], $matches)===1 )
			{
				$aData['imgname'] = $matches[1];
			}
			elseif( isset($p['Headers']['content-type:']) && strlen($p['Headers']['content-type:'])>0 && preg_match('/.*?name=(.*?\....).*/i', $p['Headers']['content-type:'], $matches)===1 )
			{
				$aData['imgname'] = $matches[1];
			}
			else
			{
				$aData['imgname'] = null;
			}

			if( is_null($aData['imgname']) )
			{
				$aData['imgextension'] = '';
			}
			else
			{
                $aData['imgextension'] = strtolower( pathinfo($aData['imgname'], PATHINFO_EXTENSION) );
			}
		}

		${$type}[] = $aData;
	}


	private function next_free_imgname( &$filename, $sanitize=true )
	{
		// sanitize img-basename
		if( $sanitize===true )
		{
			$pi = pathinfo($filename);
			$bn = preg_replace( '/[^a-z 0-9_@\.\-]/', '', strtolower($pi['filename']) );
			$filename = ! is_string($bn) ? $filename : $pi['dirname'] .'/'. str_replace(' ', '_', $bn) .'.'. strtolower($pi['extension']);
		}
		if( ! file_exists($filename) )
		{
			return;
		}
		$pi = pathinfo($filename);
		$n = 1;
		while( file_exists($filename) )
		{
			// we use PHP Version > 5.2.0, so pathinfo['filename'] is present  (pathinfo['filename'] is basename without extension)
			$filename = $pi['dirname'] .'/'. $pi['filename'] .'_'. strval($n++) .'.'. $pi['extension'];
		}
	}


	private function set_var_val( $k, $v )
	{
		if( ! in_array( $k, $this->aValidVars ) )
		{
			return;
		}

		switch( $k )
		{
			case 'port':
			case 'max_allowed_email_size':
				$this->$k = intval($v);
				break;

			case 'tls':
			case 'apop':
			case 'debug':
			case 'html_debug':
			case 'img_up_scale':
			case 'join_continuation_header_lines':
				if( is_bool($v) )
				{
					$this->$k = $v==true ? 1 : 0;
				}
				elseif( is_int($v) )
				{
					$this->$k = $v==1 ? 1 : 0;
				}
				elseif( is_string($v) && in_array($v, array('1','on','On','ON','true','TRUE')) )
				{
					$this->$k = 1;
				}
				elseif( is_string($v) && in_array($v, array('0','off','Off','OFF','false','FALSE')) )
				{
					$this->$k = 0;
				}
				else
				{
					$this->$k = 0;
				}
				break;

			case 'authentication_mechanism':
				$this->authentication_mechanism = $v;
				break;

			case 'valid_senders':
				$this->valid_senders = is_array($v) || is_string($v) ? (array)$v : array();
				break;

			default:
				if( in_array($k,array('hostname','user','password','workstation','realm','body_password','body_txt_start','body_txt_end'      ,  'autorotate_pattern'   )) )
				{
					$this->$k = strval($v);
				}
		}
	}




	public function __construct( $aConfig=null )
	{
		if( ! is_array($aConfig) )
		{
			return;
		}

		$this->aValidVars = get_class_vars(__CLASS__);
		foreach( $aConfig as $k=>$v )
		{
			$this->set_var_val( $k, $v );
		}

		foreach( $this->valid_senders as $k=>$v )
		{
			$this->valid_senders[$k] = str_replace(array('<','>'), '', strtolower(trim($v)));
		}

		$this->pop3                                 = new pop3_class();
		$this->pop3->hostname                       = $this->hostname;
		$this->pop3->port                           = $this->port;
		$this->pop3->tls                            = $this->tls;
		$this->pop3->realm                          = $this->realm;
		$this->pop3->workstation                    = $this->workstation;
		$this->pop3->authentication_mechanism       = $this->authentication_mechanism;
		$this->pop3->join_continuation_header_lines = $this->join_continuation_header_lines;
        $this->pop3->debug                          = $this->debug;
        $this->pop3->html_debug                     = $this->html_debug;
	}


	public function __destruct()
	{
		if( $this->connected )
		{
			$this->close();
		}
		unset($this->pop3);
	}


} // END class hnpw_pop3










class hn_ImageManipulation
{
	private $im_dst     = null;   // is the output for every intermediate method and the check-out!
	private $im_tmp     = null;   // optional intermediate image object
	private $im_flip    = null;   // optional intermediate image object
	private $im_rotate  = null;   // optional intermediate image object
	private $im_resize  = null;   // optional intermediate image object
	private $im_color   = null;   // optional intermediate image object


	// currently assumes a JPEG-image !!
    public static function jpegfile_auto_correction( $filename, $quality=95 )
    {
		if( ! is_file($filename) || ! is_writable($filename) )
			return false;
		$cor = hn_ImageManipulation::file_get_exif_orientation($filename,true);
		if( ! is_array($cor) )
			return false;
		if( $cor[0]===0 && $cor[1]===0 )
			return true;
		// correction is needed
		$img = @imagecreatefromstring(file_get_contents($filename));
		if( ! hn_ImageManipulation::is_resource_gd($img) )
			return false;
		$man = new hn_ImageManipulation($img);
		if( $cor[0]!==0 && ! $man->img_rotate($cor[0]) ) {
			@imagedestroy($img);
			unset($man);
			return false;
		}
		if( $cor[1]!==0 && ! $man->img_flip(($cor[1]===2 ? true : false)) ) {
			@imagedestroy($img);
			unset($man);
			return false;
		}
		$img = $man->img_get_result();
		$quality = is_int($quality) && $quality>0 && $quality<=100 ? $quality : 95;
		$res = @imagejpeg( $img, $filename, $quality );
		@imagedestroy($img);
		unset($man);
		return $res;
    }






	public function __construct( &$im_src )
	{
		if( ! $this->is_resource_gd($im_src) ) return;
		$this->im_dst = $im_src;
	}


    public function img_flip( $vertical=false )
    {
		$sx  = imagesx($this->im_dst);
		$sy  = imagesy($this->im_dst);
		$this->im_tmp = @imagecreatetruecolor($sx, $sy);
		if( $vertical===true )
		{
			$this->im_flip = @imagecopyresampled($this->im_tmp, $this->im_dst, 0, 0, 0, ($sy-1), $sx, $sy, $sx, 0-$sy);
		}
		else
		{
			$this->im_flip = @imagecopyresampled($this->im_tmp, $this->im_dst, 0, 0, ($sx-1), 0, $sx, $sy, 0-$sx, $sy);
		}
		if( ! $this->is_resource_gd($this->im_flip) )
			return false;
    	$this->im_dst = $this->im_flip;
    	@imagedestroy($this->im_tmp);
    	return true;
    }


    public function img_rotate( $degree, $background_color=0 )
    {
    	$background_color = is_int($background_color) && $background_color>=0 && $background_color<=255 ? $background_color : 0;
    	$degree = (is_float($degree) || is_int($degree)) && $degree > -361 && $degree < 361 ? $degree : false;
    	if($degree===false)
    		return false;
    	if( in_array($degree, array(-360,0,360)) )
    		return true;
    	$this->im_rotate = @imagerotate( $this->im_dst, $degree, $background_color );
    	if( ! $this->is_resource_gd($this->im_rotate) )
    		return false;
    	$this->im_dst = $this->im_rotate;
    	return true;
    }


    public function img_get_result()
    {
		return $this->im_dst;
    }






    public static function file_get_exif_orientation( $filename, $return_correctionArray=false )
    {
		// first value is rotation-degree and second value is flip-mode: 0=NONE | 1=HORIZONTAL | 2=VERTICAL
		$corrections = array(
			'1' => array(  0, 0),
			'2' => array(  0, 1),
			'3' => array(180, 0),
			'4' => array(  0, 2),
			'5' => array(270, 1),
			'6' => array(270, 0),
			'7' => array( 90, 1),
			'8' => array( 90, 0)
		);
		if( ! function_exists('exif_read_data') )
			return false;
		$exif = @exif_read_data($filename, 'IFD0');
		if( ! is_array($exif) || ! isset($exif['Orientation']) || ! in_array(strval($exif['Orientation']), array_keys($corrections)) )
			return false;
		if( $return_correctionArray !== true )
			return intval($exif['Orientation']);
		return $corrections[strval($exif['Orientation'])];
    }




	public function __destruct()
	{
//		if( $this->is_resource_gd($this->im_dst) )     @imagedestroy($this->im_dst);
		if( $this->is_resource_gd($this->im_tmp) )     @imagedestroy($this->im_tmp);
		if( $this->is_resource_gd($this->im_flip) )    @imagedestroy($this->im_flip);
		if( $this->is_resource_gd($this->im_rotate) )  @imagedestroy($this->im_rotate);
		if( $this->is_resource_gd($this->im_resize) )  @imagedestroy($this->im_resize);
		if( $this->is_resource_gd($this->im_color) )   @imagedestroy($this->im_color);
	}


	public static function is_resource_gd( &$var )
	{
		return is_resource($var) && strtoupper(substr(get_resource_type($var),0,2))=='GD' ? true : false;
	}

}


