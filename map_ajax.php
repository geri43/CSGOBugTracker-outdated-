<?php
include_once("config.php");
function eligibleToEdit($connection,$profile,$map_id,$need_priv,$rank) {
	if ($rank>1 || ($need_priv==0 && $rank>0)) {
		return true;
	} else {
		$priviliged = mysqli_fetch_array(mysqli_query($connection,"SELECT count(1) as count FROM map_mods WHERE map_id='$map_id' AND user_id='$profile'"))["count"];
		if ($priviliged==1) {
			return true;
		}
	}
	return false;
}
if ($logged) {
	if (isset($_GET["setstate"])) {
			$success = false;
			$message = "An error has happened.";
			if (isset($_GET["bug_id"]) && isset($_GET["state"])) {
				$bug_id = intval($_GET["bug_id"]);
				$state = intval($_GET["state"]);
				$valid = true;
				$response = "";
				$get_bug_data = mysqli_query($connection,"SELECT map_id FROM bugs WHERE id='$bug_id'");
				if (mysqli_num_rows($get_bug_data)==1) {
					$bug_data = mysqli_fetch_array($get_bug_data);
					$privilege_to_mod = mysqli_fetch_array(mysqli_query($connection,"SELECT privilege_to_mod FROM maps WHERE id='$bug_data[map_id]'"))["privilege_to_mod"];
					if (!eligibleToEdit($connection,$profile,$bug_data["map_id"],$privilege_to_mod,$user_data["rank"])) {
						$message = "This map can be only edited by privileged moderators.";
					} else {
						if ($state==1) {
							mysqli_query($connection,"UPDATE bugs SET state=1 WHERE state=0 AND id='$bug_id'");
							mysqli_query($connection,"INSERT INTO mod_log (mod_user_id,action,message,time,bug_id) VALUES ('$profile','set_state','1','$time','$bug_id')");
							$response = "confirmed";
						}
						else if ($state==3) {
							mysqli_query($connection,"UPDATE bugs SET state=3 WHERE state=0 AND id='$bug_id'");
							mysqli_query($connection,"INSERT INTO mod_log (mod_user_id,action,message,time,bug_id) VALUES ('$profile','set_state','3','$time','$bug_id')");
							$response = "removed";
						}
						else if ($state==2) {
							// Resolve_date is a bit pointless, because we now have full moderation history table
							mysqli_query($connection,"UPDATE bugs SET state=2,resolve_date='$time' WHERE state=1 AND id='$bug_id'");
							mysqli_query($connection,"INSERT INTO mod_log (mod_user_id,action,message,time,bug_id) VALUES ('$profile','set_state','2','$time','$bug_id')");
							$response = "marked as fixed";
						} else {
							$valid = false;
						}
						if ($valid) {
							$success = true;
							$message = "Bug $response.";
						}
					}
				}
			}
			$json = array(
					'success' => $success,
					'msg' => $message
			);
			echo json_encode($json);
	}
	else if (isset($_GET["comment"])) {
			$success = false;
			$message = "An error happened.";
			if (isset($_POST["bug_id"]) && isset($_POST["comment"])) {
				$bug_id = intval($_POST["bug_id"]);
				$get_bug_data = mysqli_query($connection,"SELECT map_id FROM bugs WHERE id='$bug_id'");
				if (mysqli_num_rows($get_bug_data)==1) {
					$bug_data = mysqli_fetch_array($get_bug_data);
					$privilege_to_mod = mysqli_fetch_array(mysqli_query($connection,"SELECT privilege_to_mod FROM maps WHERE id='$bug_data[map_id]'"))["privilege_to_mod"];
					if (!eligibleToEdit($connection,$profile,$bug_data["map_id"],$privilege_to_mod,$user_data["rank"])) {
						$message = "This map can be only edited by privileged moderators.";
					} else {
						$comment = htmlspecialchars(mysqli_real_escape_string($connection,$_POST["comment"]));
						mysqli_query($connection,"INSERT INTO mod_log (mod_user_id,action,message,time,bug_id) VALUES ('$profile','comment','$comment','$time','$bug_id')");
						$success = true;
						$message = "Comment added.";
					}
				}
			}
			$json = array(
					'success' => $success,
					'msg' => $message
			);
			echo json_encode($json);
	}
	else if (isset($_GET["post"])) {
			$success = false;
			$get = array();
			$message = "We were unable to add your bug report.";
			if ($user_data["ban"]==1) {
				$message = "You are banned because of abusing our system.";
			}
			else if (isset($_POST["description"]) && isset($_POST["type"]) && isset($_POST["media"]) && isset($_POST["map"]) && isset($_POST["coords"])) {
				$success = false;
				$spam = $time - 10;
				$getmsg = mysqli_fetch_array(mysqli_query($connection,"SELECT COUNT(*) as count FROM bugs WHERE user_id = '$profile' AND register_date > '$spam'"));
				if ($getmsg["count"] > 0) {
					$message = "You are sending bug reports too fast. Please wait 10 seconds between each report.";
				}
				else {
					// Desc check
					$raw_desc = $_POST["description"];
					$desc = htmlspecialchars(mysqli_real_escape_string($connection,$raw_desc));
					if(strlen($desc) > 500 OR strlen($desc) < 2) {
						$message = "The allowed char is limit 2-500!";
					}
					else {
						// Type check
						$type = intval($_POST["type"]);
						if ($type>=0 && $type<=14) {
							// Media check
							$url = mysqli_real_escape_string($connection,$_POST["media"]);
							if (filter_var($url, FILTER_VALIDATE_URL) === FALSE) {
								$message = "Not a valid url.";
							} else {
								$parsed = parse_url($url);
								$host = preg_replace('#^www\.(.+\.)#i', '$1', $parsed['host']);
								if (!($host == "youtube.com" || $host=="imgur.com" || $host=="i.imgur.com" || $host=="gfycat.com")) {
									$message = "We only allow URLs from youtube.com, imgur.com or gfycat.com. (Yours: $host)";
								} else {
									// Map check
									$map_id = intval($_POST["map"]);
									$get_map = mysqli_query($connection,"SELECT * FROM maps WHERE id='$map_id'");
									if (mysqli_num_rows($get_map)!=1) {
										$message = "Selected map is not valid.";
									} else {
										// Coords check
										$map_data = mysqli_fetch_array($get_map);
										$coords = intval($_POST["coords"]);
										if ($coords>=0 && $coords < (($map_data["width"]/$map_data["grid_size"])*($map_data["height"]/$map_data["grid_size"]))) {
											// Good
											$message = "Bug submitted!";
											$success = true;
											mysqli_query($connection,"INSERT INTO `bugs` (user_id,coords,map_id,type,register_date,description,media) VALUES ('$profile','$coords','$map_id','$type','$time','$desc','$url')") or die(mysql_error());
											$id = mysqli_insert_id($connection);
											$r = mysqli_query($connection,"SELECT id,bugs.user_id,coords,type,state,register_date,resolve_date,description,media,priority,steam_persona,steam_avatar,steam_id FROM bugs JOIN users ON users.user_id=bugs.user_id WHERE id='$id'");
											while ($s=mysqli_fetch_assoc($r)) {
												$get = $s;
											}
										} else {
											$message = "Selected coords are not valid.";
										}
									}
								}
							}
						}
						else {
							$message = "Invalid type!";
						}
					}
				}
			}
			$json = array(
					'success' => $success,
					'msg' => $message,
					'get' => json_encode($get)
			);
			echo json_encode($json);
	}
}
?>