<?php

/**
 * Last update on February 2013
 * EL
**/

define ('STATUS_NEW', 0);
define ('STATUS_FINAL', 1);
define ('STATUS_RESCHEDULED', 2);
define ('STATUS_CANCELLED', 3);

import('lib.pkp.classes.who.MeetingAttendance');

class MeetingAction extends Action {

	/**
	 * Constructor.
	 */
	function MeetingAction() {
		parent::Action();
	}
	

	function cancelMeeting($meetingId, $user = null){
		
		if ($user == null) $user =& Request::getUser();

		$meetingDao =& DAORegistry::getDAO('MeetingDAO');
		$meeting =& $meetingDao->getMeetingById($meetingId);

		/*Only the author can cancel the meeting*/
		if ($meeting->getUploader() == $user->getId()) {
			if (!HookRegistry::call('Action::cancelMeeting', array(&$meetingId))) {
				//$meetingDao->cancelMeeting($meetingId);
				$meetingDao->updateStatus($meetingId, STATUS_CANCELLED);
			} return true;
			
		}
		return false;
	
	}
	
	/*
	 * Last update: EL on February 25th 2013
	 */
	
	function saveMeeting($meetingId,$selectedSubmissions,$meetingDate, $meetingLength, $investigator, $location = null){
	
		$user =& Request::getUser();
				
		$journal =& Request::getJournal();
		$journalId = $journal->getId();
		//$selectedSubmissions =& $selectedSubmissions;
		$meetingDao =& DAORegistry::getDAO('MeetingDAO');
		$meetingSubmissionDao =& DAORegistry::getDAO('MeetingSubmissionDAO');
		$meetingAttendanceDao =& DAORegistry::getDAO('MeetingAttendanceDAO');		
		
		/*
		 * Parse date
		 * */
		if ($meetingDate != null) {
			$meetingDateParts = explode('-', $meetingDate);
			$tmp = explode(' ', $meetingDateParts[2]);
			$meetingTime = $tmp[1];
			$meetingTimeMarker = $tmp[2];
			$meetingTimeParts = explode(':', $meetingTime);
			$hour = intval($meetingTimeParts[0]);
			
			if(strcasecmp($meetingTimeMarker, 'pm') == 0) {
				if($hour != 12) $hour += 12;
			}
			else {
				if($hour == 12) $hour = 0;
			}
			$meetingDate = mktime($hour, (int)$meetingTimeParts[1], 0, (int)$meetingDateParts[1], (int)$meetingDateParts[2], (int)$meetingDateParts[0]);
		}
		/**
		 * Create new meeting
		 */
		$isNew = true;
		if($meetingId == null) {
			if($meetingId == 0) {
				$meetingId = $meetingDao->createMeeting($user->getCommitteeId(), $meetingDate, $meetingLength, $location, $investigator, $status = 0);
				
				$ercReviewersDao =& DAORegistry::getDAO('ErcReviewersDAO');
				
				// Insert Attendance of the external reviewers & Investigators
				$reviewAssignmentDao =& DAORegistry::getDAO('ReviewAssignmentDAO');
				$articleDao =& DAORegistry::getDAO('ArticleDAO');
				foreach ($selectedSubmissions as $submission){
					
					// For external reviewers
					$reviewAssignments =& $reviewAssignmentDao->getReviewAssignmentsByArticleId($submission);
					foreach ($reviewAssignments as $reviewAssignment) 
					if ((!$ercReviewersDao->reviewerExists($journalId, $user->getCommitteeId(), $reviewAssignment->getReviewerId())) && (!$meetingAttendanceDao->attendanceExists($meetingId, $reviewAssignment->getReviewerId())))
					$meetingAttendanceDao->insertMeetingAttendance($meetingId, $reviewAssignment->getReviewerId(), MEETING_EXTERNAL_REVIEWER);
					
					// For investigator
					if ($investigator == 1)	{
						$article =& $articleDao->getArticle($submission);
						if (!$meetingAttendanceDao->attendanceExists($meetingId, $article->getUserId())) $meetingAttendanceDao->insertMeetingAttendance($meetingId, $article->getUserId(), MEETING_INVESTIGATOR);
					}			
				}
				
				// Insert Attendance of the reviewers
				$reviewers =& $ercReviewersDao->getReviewersBySectionId($journalId, $user->getCommitteeId());						
				foreach($reviewers as $reviewer) if (!$meetingAttendanceDao->attendanceExists($meetingId, $reviewer->getId())) $meetingAttendanceDao->insertMeetingAttendance($meetingId, $reviewer->getId(), MEETING_ERC_MEMBER);
				
				
				// Insert Attendance of the secretary(ies)
				$sectionEditorsDao =& DAORegistry::getDAO('SectionEditorsDAO');
				$secretaries =& $sectionEditorsDao->getEditorsBySectionId($journalId, $user->getCommitteeId());
				foreach($secretaries as $secretary) {
					if (!$meetingAttendanceDao->attendanceExists($meetingId, $secretary->getId())) {
						if ($secretary->getId() == $user->getId()) $meetingAttendanceDao->insertMeetingAttendance($meetingId,$secretary->getId(), MEETING_SECRETARY, MEETING_REPLY_ATTENDING);
						else $meetingAttendanceDao->insertMeetingAttendance($meetingId,$secretary->getId(), MEETING_SECRETARY);
					}
				}
			}
		/**
		 * Update an existing meeting
		 */
		}else{ 
			 $isNew = false;
			 $meetingSubmissionDao->deleteMeetingSubmissionsByMeetingId($meetingId);
			 $meeting = $meetingDao->getMeetingById($meetingId);
			 //check if new meeting date is equal to old meeting date
			 $oldDate = 0;
			 $diff = $meetingDate - strtotime($meeting->getDate());
			 if($diff != 0)
				$oldDate = $meeting->getDate();
			 
			 $meetingSubmissionDao->deleteMeetingSubmissionsByMeetingId($meetingId);
		}

		/**
		 * Store submissions to be discussed
		 */
		if (count($selectedSubmissions) > 0) {
			for ($i=0;$i<count($selectedSubmissions);$i++) {
				/*Set submissions to be discussed in the meeting*/
					// EL on February 25th 2013
					// Removed
					//$selectedProposals[$i];
				$meetingSubmissionDao->insertMeetingSubmission($meetingId,$selectedSubmissions[$i]);
			}
		}
		
		if($isNew) {
			Request::redirect(null, null, 'notifyUsersNewMeeting', $meetingId);
		} else if($oldDate!=0){
			//reset reply of all reviewers
			$meetingAttendanceDao->resetReplyOfUsers($meeting);
			//update meeting date since old date != new date
			$meeting->setDate($meetingDate);
			$meetingDao->updateMeetingDate($meeting);
			//update meeting as rescheduled
			$meetingDao->updateStatus($meetingId, STATUS_RESCHEDULED);
			Request::redirect(null, null, 'notifyReviewersChangeMeeting', array($meetingId, $oldDate));
		}
		return $meetingId;
	}
	
	/**
	 * Notify reviewers of new meeting set by section editor
	 * Added by ayveemallare 7/12/2011
	 * Last modified by EL on February 26th 2013
	 * And moved from SectionEditorAction to here
	 */

	function notifyReviewersNewMeeting($meeting, $reviewerAttendances, $submissionIds, $send = false) {
		$journal =& Request::getJournal();
		$user =& Request::getUser();
		$articleDao =& DAORegistry::getDAO('ArticleDAO');
			// EL on February 26th 2013
			// Definition of the variable
			$submissions = (string)'';
		$num=1;

		foreach($submissionIds as $submissionId) {
			$submission = $articleDao->getArticle($submissionId, $journal->getId(), false);
			$submissions .= $num.". '".$submission->getLocalizedTitle()."' by ".$submission->getAuthorString()."\n";
			$num++;
		}

		$userDao =& DAORegistry::getDAO('UserDAO');
		$reviewers = array();
		foreach($reviewerAttendances as $reviewerAttendance) {
			$reviewer = $userDao->getUser($reviewerAttendance->getUserId());
			array_push($reviewers, $reviewer);
		}

		$reviewerAccessKeysEnabled = $journal->getSetting('reviewerAccessKeysEnabled');

		$preventAddressChanges = $reviewerAccessKeysEnabled;

		import('classes.mail.MailTemplate');
		$email = new MailTemplate('MEETING_NEW');

		if($preventAddressChanges) {
			$email->setAddressFieldsEnabled(false);
		}

		if($send && !$email->hasErrors()) {
			HookRegistry::call('MeetingAction::notifyReviewersNewMeeting', array(&$meeting, &$reviewers, &$submissions, &$email));
			// EL on February 26th 2013
			// Replaced "reviewerAccessKyesEnabled" by "reviewerAccessKeysEnabled"	
			if($reviewerAccessKeysEnabled) {
				import('lib.pkp.classes.security.AccessKeyManager');
				import('pages.reviewer.ReviewerHandler');
				$accessKeyManager = new AccessKeyManager();
			}
				
			if($preventAddressChanges) {
				// Ensure that this messages goes to the reviewers, and the reviewers ONLY.
				$email->clearAllRecipients();
				foreach($reviewers as $reviewer) {
					$email->addRecipient($reviewer->getEmail(), $reviewer->getFullName());
				}
			}
			$email->send();
			return true;
		} else {
			if(!Request::getUserVar('continued') || $preventAddressChanges) {
				foreach($reviewers as $reviewer) {
					$email->addRecipient($reviewer->getEmail(), $reviewer->getFullName());
				}
			}
			
			// CC the secretary(ies) of the committee
			$sectionEditorsDao =& DAORegistry::getDAO('SectionEditorsDAO');
			$secretaries =& $sectionEditorsDao->getEditorsBySectionId($journal->getId(), $user->getCommitteeId());
			foreach ($secretaries as $secretary) $email->addCc($secretary->getEmail(), $secretary->getFullName());
			
							
			if(!Request::getUserVar('continued')) {
				$dateLocation = (string)'';
				if($meeting->getDate() != null) {
					$dateLocation .= 'Date: '.strftime('%B %d, %Y %I:%M %p', strtotime($meeting->getDate()))."\n";
				}
				if($meeting->getLength() != null) {
					$dateLocation .= 'Length: '.$meeting->getLength()."mn\n";
				}
				if($meeting->getLocation() != null) {
					$dateLocation .= 'Location: '.$meeting->getLocation()."\n";
				}
				$replyUrl = Request::url(null, 'reviewer', 'viewMeeting', $meeting->getId()
					// EL on february 26th 2013
					// Undefined - replaced "reviewerAccessKeyEnabled" by "reviewerAccessKeysEnabled"
					, $reviewerAccessKeysEnabled?array('key' => 'ACCESS_KEY'):array()
				);
				$sectionDao =& DAORegistry::getDAO('SectionDAO');
				$erc =& $sectionDao->getSection($meeting->getUploader());
				$paramArray = array(
					'ercTitle' => $erc->getLocalizedTitle(),
					'ercAbbrev' => $erc->getLocalizedAbbrev(),
					'submissions' => $submissions,
					'dateLocation' => $dateLocation,
					'replyUrl' => $replyUrl,
					'secretaryName' => $user->getFullName(),
					'secretaryFunctions' => $user->getFunctions()
				);
				$email->assignParams($paramArray);
			}
			// EL on February 26th 2013
			// Replaced submissionsIds by submissionIds
			// + moved the paramters as additional parameters
			$email->displayEditForm(Request::url(null, null, 'notifyUsersNewMeeting', $meeting->getId()));
			return false;
		}
		return true;
	}

	/**
	 * Notify an investigator of a new meeting set by section editor
	 * Added by EL on February 28th 2013
	 * And moved from SectionEditorAction to here
	 */

	function notifyExternalReviewerNewMeeting($meeting, $externalReviewerAttendance, $attendanceIncrementNumber, $submissionIds, $send = false) {
		$journal =& Request::getJournal();
		$user =& Request::getUser();
		$articleDao =& DAORegistry::getDAO('ArticleDAO');
		$reviewAssignmentDao =& DAORegistry::getDAO('ReviewAssignmentDAO');
		$num=1;
		$submissions = (string)'';

		foreach($submissionIds as $submissionId) {
			$submission = $articleDao->getArticle($submissionId, $journal->getId(), false);
			$reviewAssignments =& $reviewAssignmentDao->getReviewAssignmentsByArticleId($submissionId);
			foreach ($reviewAssignments as $reviewAssignment) {
				if ($reviewAssignment->getReviewerId() == $externalReviewerAttendance->getUserId()){
					$submissions .= $num.". '".$submission->getLocalizedTitle()."' by ".$submission->getAuthorString()."\n";
					$num++;
				}
			}
		}

		$reviewerAccessKeysEnabled = $journal->getSetting('reviewerAccessKeysEnabled');

		$preventAddressChanges = $reviewerAccessKeysEnabled;
		
		$userDao =& DAORegistry::getDAO('UserDAO');
		$extReviewer =& $userDao->getUser($externalReviewerAttendance->getUserId());

		import('classes.mail.MailTemplate');
		$email = new MailTemplate('MEETING_NEW_EXTERNAL_REVIEWER');
		
		if($preventAddressChanges) {
			$email->setAddressFieldsEnabled(false);
		}
		
		if($send && !$email->hasErrors()) {
			HookRegistry::call('MeetingAction::notifyExternalReviewerNewMeeting', array(&$meeting, &$extReviewer, $attendanceIncrementNumber, &$submissions, &$email));

			if($reviewerAccessKeysEnabled) {
				import('lib.pkp.classes.security.AccessKeyManager');
				import('pages.reviewer.ReviewerHandler');
				$accessKeyManager = new AccessKeyManager();
			}
				
			if($preventAddressChanges) {
				// Ensure that this messages goes to the reviewers, and the reviewers ONLY.
				$email->clearAllRecipients();
				$email->addRecipient($extReviewer->getEmail(), $extReviewer->getFullName());
			}
			
			$email->send();
			return true;
		} else {
			if(!Request::getUserVar('continued') || $preventAddressChanges) {
				$email->addRecipient($extReviewer->getEmail(), $extReviewer->getFullName());
			}
			
			// CC the secretary(ies) of the committee
			$sectionEditorsDao =& DAORegistry::getDAO('SectionEditorsDAO');
			$secretaries =& $sectionEditorsDao->getEditorsBySectionId($journal->getId(), $user->getCommitteeId());
			foreach ($secretaries as $secretary) $email->addCc($secretary->getEmail(), $secretary->getFullName());
			
							
			if(!Request::getUserVar('continued')) {
				$dateLocation = (string)'';
				if($meeting->getDate() != null) {
					$dateLocation .= 'Date: '.strftime('%B %d, %Y %I:%M %p', strtotime($meeting->getDate()))."\n";
				}
				if($meeting->getLength() != null) {
					$dateLocation .= 'Length: '.$meeting->getLength()."mn\n";
				}
				if($meeting->getLocation() != null) {
					$dateLocation .= 'Location: '.$meeting->getLocation()."\n";
				}
				$dateLocation .= 'Number of proposal(s) to review: '.count($submissionIds)."\n";
				
				$replyUrl = Request::url(null, 'reviewer', 'viewMeeting', $meeting->getId(), $reviewerAccessKeysEnabled?array('key' => 'ACCESS_KEY'):array());
				
				$sectionDao =& DAORegistry::getDAO('SectionDAO');
				$erc =& $sectionDao->getSection($meeting->getUploader());
				$paramArray = array(
					'ercTitle' => $erc->getLocalizedTitle(),
					'extReviewerFullName' => $extReviewer->getFullName(),
					'submissions' => $submissions,
					'dateLocation' => $dateLocation,
					'replyUrl' => $replyUrl,
					'secretaryName' => $user->getFullName(),
					'secretaryFunctions' => $user->getFunctions()
				);
				$email->assignParams($paramArray);
			}
			// EL on February 26th 2013
			// Replaced submissionsIds by submissionIds
			// + moved the paramters as additional parameters
			$email->displayEditForm(Request::url(null, null, 'notifyExternalReviewersNewMeeting', array($meeting->getId(), $attendanceIncrementNumber)));
			return false;
		}
		return true;
	}
	
	/**
	 * Notify an investigator of a new meeting set by section editor
	 * Added by EL on February 28th 2013
	 * And moved from SectionEditorAction to here
	 */

	function notifyInvestigatorNewMeeting($meeting, $investigatorAttendance, $attendanceIncrementNumber, $submissionIds, $send = false) {
		$journal =& Request::getJournal();
		$user =& Request::getUser();
		$articleDao =& DAORegistry::getDAO('ArticleDAO');
		$num=1;
		$submissions = (string)'';

		foreach($submissionIds as $submissionId) {
			$submission = $articleDao->getArticle($submissionId, $journal->getId(), false);
			if ($submission->getUserId() == $investigatorAttendance->getUserId()) {
				$submissions .= $num.". '".$submission->getLocalizedTitle()."'\n";
				$num++;
			}
		}

		$userDao =& DAORegistry::getDAO('UserDAO');
		$investigator =& $userDao->getUser($investigatorAttendance->getUserId());

		import('classes.mail.MailTemplate');
		$email = new MailTemplate('MEETING_NEW_INVESTIGATOR');

		if($send && !$email->hasErrors()) {
			HookRegistry::call('MeetingAction::notifyInvestigatorNewMeeting', array(&$meeting, &$investigator, $attendanceIncrementNumber, &$submissions, &$email));
			$email->send();
			return true;
		} else {
			if(!Request::getUserVar('continued')) {
				$email->addRecipient($investigator->getEmail(), $investigator->getFullName());
			}
			
			// CC the secretary(ies) of the committee
			$sectionEditorsDao =& DAORegistry::getDAO('SectionEditorsDAO');
			$secretaries =& $sectionEditorsDao->getEditorsBySectionId($journal->getId(), $user->getCommitteeId());
			foreach ($secretaries as $secretary) $email->addCc($secretary->getEmail(), $secretary->getFullName());
			
							
			if(!Request::getUserVar('continued')) {
				$dateLocation = (string)'';
				if($meeting->getDate() != null) {
					$dateLocation .= 'Date: '.strftime('%B %d, %Y %I:%M %p', strtotime($meeting->getDate()))."\n";
				}
				if($meeting->getLength() != null) {
					$dateLocation .= 'Length: '.$meeting->getLength()."mn\n";
				}
				if($meeting->getLocation() != null) {
					$dateLocation .= 'Location: '.$meeting->getLocation()."\n";
				}
				$dateLocation .= 'Number of proposal(s) to review: '.count($submissionIds)."\n";
				
				$replyUrl = (string)'';
				$urlFirst = true;
				foreach ($submissionIds as $submissionId){
					if ($urlFirst) $replyUrl .= Request::url(null, 'author', 'submissionReview', $submissionId);
					else $replyUrl .= 'Or:\n'.Request::url(null, 'author', 'submissionReview', $submissionId);					
				}
				
				
				
				$sectionDao =& DAORegistry::getDAO('SectionDAO');
				$erc =& $sectionDao->getSection($meeting->getUploader());
				$paramArray = array(
					'ercTitle' => $erc->getLocalizedTitle(),
					'investigatorFullName' => $investigator->getFullName(),
					'submissions' => $submissions,
					'dateLocation' => $dateLocation,
					'replyUrl' => $replyUrl,
					'secretaryName' => $user->getFullName(),
					'secretaryFunctions' => $user->getFunctions()
				);
				$email->assignParams($paramArray);
			}
			// EL on February 26th 2013
			// Replaced submissionsIds by submissionIds
			// + moved the paramters as additional parameters
			$email->displayEditForm(Request::url(null, null, 'notifyInvestigatorsNewMeeting', array($meeting->getId(), $attendanceIncrementNumber)));
			return false;
		}
		return true;
	}
	
		
	function setMeetingFinal($meetingId, $user=null){
		if ($user == null) $user =& Request::getUser();

		$meetingDao =& DAORegistry::getDAO('MeetingDAO');
		$meeting =& $meetingDao->getMeetingById($meetingId);

		/*Only the author can set the meeting final*/
		if ($meeting->getUploader() == $user->getId()) {
			if (!HookRegistry::call('Action::setMeetingFinal', array(&$meetingId))) {
				$meetingDao->updateStatus($meetingId, STATUS_FINAL);
			} 
			return $meetingId;
			
		}
		return false;
	
	}

	/**
	 * Added by EL on February 27th 2013
	 * Reply the attendance of the user
	 */
	function replyAttendanceForUser($meetingId, $userId, $attendance){
		$meetingAttendanceDao =& DAORegistry::getDAO('MeetingAttendanceDAO');
				
		$meetingDao =& DAORegistry::getDao('MeetingDAO');
		$meeting = $meetingDao->getMeetingByMeetingAndReviewerId($meetingId, $userId);
		
		$meeting->setIsAttending($attendance);
		
		$meetingAttendanceDao->updateReplyOfAttendance($meeting);
		return true;	
	}
	
	
}
