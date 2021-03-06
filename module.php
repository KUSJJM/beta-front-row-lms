<?php

use frontRow\Comment;
use frontRow\Link;
use frontRow\Module;
use frontRow\ModulePage;
use frontRow\Post;
use frontRow\UploadFile;
use frontRow\User;

require_once '_includes/pdoConnect.php';
require_once '_includes/authenticate.php';
require_once '_includes/frontRow/Comment.php';
require_once '_includes/frontRow/Link.php';
require_once '_includes/frontRow/Module.php';
require_once '_includes/frontRow/ModulePage.php';
require_once '_includes/frontRow/Post.php';
require_once '_includes/frontRow/UploadFile.php';
require_once '_includes/frontRow/User.php';

if(isset($_GET['moduleID'])) {
    //Check that the module exists, avoids any random user additions to the _GET
    $sql = 'SELECT COUNT(*) FROM module WHERE moduleID = :moduleID';
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':moduleID', $_GET['moduleID']);
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        header('Location: home.php');
    } else {
        //Set up user
        $stmt = $db->prepare('SELECT *
        FROM user
        WHERE user.kNumber=:kNumber');
        $stmt->bindParam(':kNumber', $_SESSION['username']);
        $stmt->execute();

        $user = $stmt->fetchObject('User');
        
        //Get Modules
        $user->setModules($db);
        $modules = $user->modules;
        
        //Create var for current module in current scope
        $currentModule;
                
        //Set pages for users modules and instantiate $currentModule
        foreach($modules as $module) {
            $module->setModulePage($db);
            if ($_GET['moduleID'] == $module->moduleID) {
                $currentModule = $module;
            }
        }
        
        //If $currentModule was not instantiated then it's not one of the users modules, so it is set up here
        if(!isset($currentModule)) {
            $stmt = $db->prepare('SELECT *
            FROM module
            WHERE moduleID = :moduleID');
            $stmt->bindParam(':moduleID', $_GET['moduleID']);
            $stmt->execute();
            $currentModule = $stmt->fetchObject('Module');
            $currentModule->setModulePage($db);
        }
        
        //Check that a module page is set, if not set it to the first stored page
        if(isset($_GET['modulePage'])) {
            if(in_array($_GET['modulePage'], $currentModule->modulePage)) {
                $currentModule->setCurrentPage($db, $_GET['modulePage']);
            } else {
                if(isset($currentModule->modulePage[0])) {
                    $currentModule->setCurrentPage($db, $currentModule->modulePage[0]);
                } else {
                    header('Location: home.php');
                }
            }
        } else {
            if(isset($currentModule->modulePage[0])) {
                $currentModule->setCurrentPage($db, $currentModule->modulePage[0]);
            } else {
                header('Location: home.php');
            }
        }
        
        $currentPage = $currentModule->currentPage;
        $currentPage->getPosts($db);
        $currentPageID = $currentPage->pageID;
            
        //Get user privs for current module
        $sql = 'SELECT permission FROM userModule WHERE kNumber = :kNumber AND moduleID = :moduleID';
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':moduleID', $_GET['moduleID']);
        $stmt->bindParam(':kNumber', $_SESSION['username']);
        $stmt->execute();
        
        //Set priv variable for later use
        if($stmt->fetchColumn() == 1){
            $priv = true;
        } else {
            $priv = false;
        }
        
        
        //Handle Posts - potentially shift into a require/include
        $moduleID = $_GET['moduleID'] . '/';
        
        //Create Upload folder if it's not already there.
        $uploadFolder = __DIR__ . '/_uploads';
        
        if(!is_dir($uploadFolder)) {
            mkdir($uploadFolder, 0755);
        }
        
        $destination = __DIR__ . '/_uploads/' . $moduleID;

        if(!is_dir($destination)) {
            mkdir($destination, 0755);
        }
        
        if(isset($_POST['makePost']) && isset($_POST['postTitle'])) {
            $sql = 'INSERT INTO post (title, pageID, content, commentsAllowed, dateTimePosted)
                    VALUES (:title, :pageID, :content, :commentsAllowed, now())';
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':pageID', $currentPageID);
            $stmt->bindParam(':title', $_POST['postTitle']);
            $stmt->bindParam(':content', $_POST['postContent']);
            if(isset($_POST['commentsAllowed'])) {
                $commentsAllowed = 1;
                $stmt->bindParam(':commentsAllowed', $commentsAllowed);
            } else {
                $commentsAllowed = 0;
                $stmt->bindParam(':commentsAllowed', $commentsAllowed);
            }
            $stmt->execute();

            //This can also be done with a PHP method, which i imagine does the exact same thing.
            $sql = 'SELECT LAST_INSERT_ID();';
            $stmt = $db->prepare($sql);
            $stmt->execute();
            $lastID = $stmt->fetchColumn();

            //print_r($lastID);
            
            if(isset($_POST['fileChoice'])) {

                $postFiles = $_POST['fileChoice'];

                foreach($postFiles as $file) {
                $sql = 'INSERT INTO postFile (postID, fileName)
                        VALUES (:postID, :fileName)';
                $stmt = $db->prepare($sql);
                $stmt->bindParam(':postID', $lastID);
                $stmt->bindParam(':fileName', $file);
                $stmt->execute();
                }
                print_r($_POST['fileChoice']);
            }

            if(isset($_POST['linkName']) && isset($_POST['linkHref'])){
                $linkNames = $_POST['linkName'];
                $linkHrefs = $_POST['linkHref'];

                $linkNumber = count($linkNames);

                for($i = 0; $i < $linkNumber; $i++) {
                    $sql = 'INSERT INTO postLink (postID, linkName, linkHref)
                            VALUES (:postID, :linkName, :linkHref)';
                    $stmt = $db->prepare($sql);
                    $stmt->bindParam(':postID', $lastID);
                    $stmt->bindParam(':linkName', $linkNames[$i]);
                    $stmt->bindParam(':linkHref', $linkHrefs[$i]);
                    $stmt->execute();
                }
            }
        }
        
        //Run this if a comment is posted.
        if(isset($_POST['postComment'])) {
            $sql = 'INSERT INTO postComment (postID, kNumber, commentText, dateTimeCommented)
                    VALUES (:postID, :kNumber, :commentText, now())';
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':postID', $_POST['postID']);
            $stmt->bindParam(':kNumber', $_SESSION['username']);
            $stmt->bindParam(':commentText', $_POST['commentText']);
            $stmt->execute();
        }
        
        //Delete Post
        if(isset($_POST['deletePost'])) {
            //To delete a post, it's first necessary to delete everything associated to the foreign key, or use a cascade?
            //Delete PostFile Links, don't delete File as other posts may be link to the same file.
            $sql = 'DELETE FROM postFile
                    WHERE postID = :postID';
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':postID', $_POST['postID']);
            $stmt->execute();
            //Delete postLinks
            $sql = 'DELETE FROM postLink
                    WHERE postID = :postID';
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':postID', $_POST['postID']);
            $stmt->execute();
            //Delete Comments on the post
            $sql = 'DELETE FROM postComment
                    WHERE postID = :postID';
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':postID', $_POST['postID']);
            $stmt->execute();
            
            //Delete the post itself
            $sql = 'DELETE FROM post
                    WHERE postID = :postID';
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':postID', $_POST['postID']);
            $stmt->execute();
        }
        
        if(isset($_POST['deleteComment'])) {
            //Delete Comment on the post
            $sql = 'DELETE FROM postComment
                    WHERE commentID = :commentID';
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':commentID', $_POST['commentID']);
            $stmt->execute();
        }
        
        //Select posts
        $sql = 'SELECT * FROM post WHERE pageID = :pageID';
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':pageID', $currentPageID);
        $stmt->execute();
        $posts = $stmt->fetchAll(PDO::FETCH_CLASS, 'Post');
        
        
    }
} else {
    header('Location: home.php');
}

?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <title>FR | Module Page</title>
        
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="author" content="Joshua Stringfellow, Jessica Wallace">
        <meta name="description" content="FrontRow Homepage.">
        
        <link href='http://fonts.googleapis.com/css?family=Abel' rel='stylesheet' type='text/css'>
        <link rel="shortcut icon" href="_img/favicon.ico" type="image/x-icon">
        
        <link rel="stylesheet" href="_css/layout.css">
    </head>
    <body>
        <header>
            <img src="_img/kulogo.png" alt="Kingston University">
            <h1><?= $currentPage->pageName ?></h1>
            <nav>
                <a href="home.php">Home</a>
                <a href="moduleCatalogue.php">Module Catalogue</a>
                <div class="dropdown">
                    <a href="#" class="dropdown-toggle">User Information</a>
                    <ul class="dropdown-content">
                        <li><a href="#"><?= $user->kNumber ?></a></li>
                        <li><a href="#"><?= $user->fName ?></a></li>
                        <li><a href="#"><?= $user->lName ?></a></li>
                    </ul>
                </div>
                <a id="logout" href="logout.php">Logout</a>
            </nav>
        </header>
        <nav>
<?php include_once '_includes/moduleNav.php'; ?>
        </nav>
        <main>
            <h2>Module: <?= $currentModule->moduleID ?> - <?= $currentModule->moduleName ?></h2>
            
            <?php if($priv) : ?>
            <article>
                <h2>Test Multiple Insert referencing initial insert ID.</h2>
                <section>
                    <!--     POST CREATION FORM               -->
                    <form method="post" action="">
                        <div>
                            <div>
                                <p>Enter Post Title:</p>
                                <input type="text" name="postTitle" required>
                                <p>Enter Post Content:</p>
                                <textarea type="text" name="postContent" required></textarea>
                                <p>Comments allowed:</p>
                                <input type="checkbox" name="commentsAllowed">
                            </div>
                            <?php $directoryContents = scandir($destination);
                            $files = array_diff($directoryContents, array('.', '..')); ?>
                            <div>
                                <p>Add files to post or remove files:</p>
                                <button type="button" id="addFileChoice">Add File</button>
                                <button type="button" id="removeFileChoice">Remove File</button>
                                <div id="file-choice-section">
                                    <select class="fileChoice" name="fileChoice[]">
                                        <?php foreach($files as $file) : ?>
                                            <option value="<?= $file ?>"><?= $file ?></option>
                                        <?php endforeach ?>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <p>Add links to post or remove links:</p>
                                <button type="button" id="addLinkChoice">Add Link</button>
                                <button type="button" id="removeLinkChoice">Remove Link</button>
                                <div id="link-choice"></div>
                            </div>
                            </div>
                        <input type="submit" name="makePost" value="Create Post">
                    </form>
                </section>
            </article>
            <?php endif ?>
            <!-- Print out posts, also revere so newest post is at the top -->
            <?php foreach(array_reverse($posts) as $post) : ?>
            <article>
                <h2><?= $post->title ?></h2>
                <section>
                <p><?= $post->content ?></p>
                <?php
                    $postID = $post->postID;
                    $sql = 'SELECT fileName FROM postFile WHERE postID = :postID';
                    $stmt = $db->prepare($sql);
                    $stmt->bindParam(':postID', $postID);
                    $stmt->execute();
                    $postFileArr = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
                ?>
                <?php if($postFileArr) : ?>
                    <h3>Files:</h3>
                <?php endif ?>
                <?php
                    foreach($postFileArr as $linkedFile){
                        echo '<p><a download target="_blank" href="_uploads/' . $moduleID . $linkedFile . '">' . $linkedFile . '</a></p>';
                    }

                ?>
                <?php
                    $sql = 'SELECT * FROM postLink WHERE postID = :postID';
                    $stmt = $db->prepare($sql);
                    $stmt->bindParam(':postID', $postID);
                    $stmt->execute();
                    $links = $stmt->fetchAll(PDO::FETCH_CLASS, 'Link');

                if($links) : ?>
                    <h3>Links:</h3>
                <?php endif ?>            
                <?php    
                    foreach($links as $link){
                        echo '<p><a target="_blank" href="' . $link->linkHref . '">' . $link->linkName . '</a></p>';
                    }
                ?>
                <?php if($post->commentsAllowed) : ?>
                    <section>
                    <?php
                        echo '<p>Comments allowed!</p>';
                        $sql = 'SELECT * FROM postComment WHERE postID = :postID';
                        $stmt = $db->prepare($sql);
                        $stmt->bindParam(':postID', $postID);
                        $stmt->execute();
                        $comments = $stmt->fetchAll(PDO::FETCH_CLASS, 'Comment');
                    
                        foreach($comments as $comment) : ?>
                            <section class="comments">
                                <h3><?= $comment->kNumber ?></h3>
                                <p><?= $comment->commentText ?></p>
                                <?php if($priv) : ?>
                                    <form method="post" action="">
                                        <input type="hidden" name="commentID" value="<?= $comment->commentID ?>">
                                        <input type="submit" name="deleteComment" value="Delete Comment">
                                    </form>
                                <?php endif ?>
                            </section>
                        <?php endforeach ?>
                    </section>
                    <section>
                            <form method="post" action="">
                                <input type="hidden" name="postID" value="<?= $post->postID ?>">
                                <textarea type="text" name="commentText" required></textarea>
                                <input type="submit" name="postComment" value="Comment">
                            </form>
                    </section>
                        <?php endif ?>
                        <?php if($priv) : ?>
                            <form method="post" action="">
                                <input type="hidden" name="postID" value="<?= $post->postID ?>">
                                <input type="submit" name="deletePost" value="Delete Post">
                            </form>
                        <?php endif ?>
                    </section>
                </article>
            <?php endforeach ?>
        </main>
    <script src="_js/postOptions.js"></script>
    <script src="_js/navToggle.js"></script>
    </body>
</html>