<?php
// contact.php - Contact Form Handler

require_once 'config.php';

// Start session if needed
session_start();

// Check if form was submitted via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get and sanitize form data
    $name = isset($_POST['name']) ? sanitizeInput($_POST['name']) : '';
    $email = isset($_POST['email']) ? sanitizeInput($_POST['email']) : '';
    $subject = isset($_POST['subject']) ? sanitizeInput($_POST['subject']) : '';
    $message = isset($_POST['message']) ? sanitizeInput($_POST['message']) : '';
    
    // Validation
    $errors = [];
    
    // Validate name
    if (empty($name)) {
        $errors[] = "Name is required";
    } elseif (strlen($name) < 2) {
        $errors[] = "Name must be at least 2 characters";
    }
    
    // Validate email
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!validateEmail($email)) {
        $errors[] = "Please enter a valid email address";
    }
    
    // Validate subject
    if (empty($subject)) {
        $errors[] = "Subject is required";
    } elseif (strlen($subject) < 3) {
        $errors[] = "Subject must be at least 3 characters";
    }
    
    // Validate message
    if (empty($message)) {
        $errors[] = "Message is required";
    } elseif (strlen($message) < 10) {
        $errors[] = "Message must be at least 10 characters";
    }
    
    // If no errors, save to database
    if (empty($errors)) {
        $conn = getDBConnection();
        
        // Prepare SQL statement to prevent SQL injection
        $stmt = $conn->prepare("INSERT INTO contact_messages (name, email, subject, message) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $name, $email, $subject, $message);
        
        if ($stmt->execute()) {
            // Success - redirect with success message
            $_SESSION['contact_success'] = "Thank you! Your message has been sent successfully. We'll get back to you soon.";
            header("Location: index.php?section=contact&status=success");
            exit();
        } else {
            // Database error
            $_SESSION['contact_error'] = "Sorry, there was an error sending your message. Please try again later.";
            header("Location: index.php?section=contact&status=error");
            exit();
        }
        
        $stmt->close();
        $conn->close();
    } else {
        // Validation errors - redirect back with errors
        $_SESSION['contact_errors'] = $errors;
        $_SESSION['contact_data'] = ['name' => $name, 'email' => $email, 'subject' => $subject, 'message' => $message];
        header("Location: index.php?section=contact&status=error");
        exit();
    }
} else {
    // If someone tries to access this file directly
    header("Location: index.php?section=contact");
    exit();
}
?>