<?php

session_start();

$conn = new mysqli(
    "localhost",
    "root",
    "",
    "ecommerce_db"
);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

$errors = [];
$success = "";
$db_error = "";

$product_name = "";
$sku = "";
$category = "";
$unit_price = "";
$quantity = "";
$description = "";
$available = 0;

$categories = [
    "Electronics",
    "Clothing",
    "Shoes",
    "Accessories",
    "Home Appliances",
    "Books",
    "Beauty Products",
    "Food and Beverages"
];

if (isset($_SESSION["success"])) {

    $success = $_SESSION["success"];

    unset($_SESSION["success"]);

}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $product_name = trim($_POST["product_name"] ?? "");

    $sku = trim($_POST["sku"] ?? "");

    $category = trim($_POST["category"] ?? "");

    $unit_price = trim($_POST["unit_price"] ?? "");

    $quantity = trim($_POST["quantity"] ?? "");

    $description = trim($_POST["description"] ?? "");

    $available = isset($_POST["available"]) &&
                 $_POST["available"] === "1" ? 1 : 0;


   
    $name_length = function_exists("mb_strlen")
        ? mb_strlen($product_name, "UTF-8")
        : strlen($product_name);

    if (empty($product_name)) {

        $errors["product_name"] = "Product name is required.";

    } elseif ($name_length < 3 || $name_length > 100) {

        $errors["product_name"] =
            "Product name must contain 3-100 characters.";

    }

    if (empty($sku)) {

        $errors["sku"] = "SKU is required.";

    } elseif (strlen($sku) > 50) {

        $errors["sku"] = "SKU cannot exceed 50 characters.";

    } else {

        $check = $conn->prepare(
            "SELECT product_id FROM products WHERE sku = ?"
        );

        $check->bind_param("s", $sku);

        $check->execute();

        $result = $check->get_result();

        if ($result->num_rows > 0) {

            $errors["sku"] = "This SKU already exists. Enter a unique SKU.";

        }

        $check->close();

    }

    if (!in_array($category, $categories, true)) {

        $errors["category"] = "Please select a valid category.";

    }


    if ($unit_price === "") {

        $errors["unit_price"] = "Unit price is required.";

    } elseif (
        !is_numeric($unit_price) ||
        (float)$unit_price <= 0 ||
        (float)$unit_price > 99999999.99
    ) {

        $errors["unit_price"] =
            "Price must be greater than zero.";

    }

    if ($quantity === "") {

        $errors["quantity"] = "Quantity is required.";

    } elseif (
        !preg_match('/^(0|[1-9][0-9]*)$/', $quantity) ||
        (float)$quantity > 2147483647
    ) {

        $errors["quantity"] =
            "Quantity must be a non-negative integer.";

    }

    $description_length = function_exists("mb_strlen")
        ? mb_strlen($description, "UTF-8")
        : strlen($description);

    if ($description_length > 1000) {

        $errors["description"] =
            "Description cannot exceed 1000 characters.";

    }

    $image_name = "";

    $upload_image = false;

    if (
        isset($_FILES["product_image"]) &&
        $_FILES["product_image"]["error"] != UPLOAD_ERR_NO_FILE
    ) {

        if ($_FILES["product_image"]["error"] !== UPLOAD_ERR_OK) {

            $errors["product_image"] =
                "There was an error uploading the image.";

        } elseif ($_FILES["product_image"]["size"] > 2 * 1024 * 1024) {

            $errors["product_image"] =
                "Image size must not exceed 2MB.";

        } else {

            $file_tmp = $_FILES["product_image"]["tmp_name"];

            $file_info = new finfo(FILEINFO_MIME_TYPE);

            $mime_type = $file_info->file($file_tmp);

            $allowed_types = [
                "image/jpeg" => "jpg",
                "image/png" => "png"
            ];

            if (!isset($allowed_types[$mime_type])) {

                $errors["product_image"] =
                    "Only JPEG and PNG images are allowed.";

            } elseif (@getimagesize($file_tmp) === false) {

                $errors["product_image"] =
                    "The uploaded file is not a valid image.";

            } else {

                $extension = $allowed_types[$mime_type];

                $image_name = bin2hex(random_bytes(16))
                              . "." . $extension;

                $upload_image = true;

            }

        }

    }

    if (empty($errors)) {

        $image_path = $upload_image
            ? "uploads/" . $image_name
            : "";

        $product_price = (float)$unit_price;

        $product_quantity = (int)$quantity;

        $sql = "INSERT INTO products
        (
            product_name,
            sku,
            category,
            unit_price,
            quantity,
            description,
            product_image,
            available_for_sale
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);

        if ($stmt) {

            $stmt->bind_param(
                "sssdissi",
                $product_name,
                $sku,
                $category,
                $product_price,
                $product_quantity,
                $description,
                $image_path,
                $available
            );

            $image_moved = true;

            if ($upload_image) {

                $upload_directory = __DIR__ . "/uploads/";

                if (!is_dir($upload_directory)) {

                    if (!mkdir($upload_directory, 0755, true)) {
                        $image_moved = false;
                    }

                }

                if ($image_moved) {

                    $image_moved = move_uploaded_file(
                        $_FILES["product_image"]["tmp_name"],
                        $upload_directory . $image_name
                    );

                }

            }


            if (!$image_moved) {

                $db_error = "Unable to upload product image.";

            } elseif ($stmt->execute()) {

                $_SESSION["success"] =
                    "Product added successfully!";

                header("Location: index.php");

                exit();

            } else {

                if ($stmt->errno == 1062) {

                    $errors["sku"] =
                        "This SKU already exists. Enter a unique SKU.";

                } else {

                    $db_error =
                        "Unable to save product. Please try again.";

                }

                if ($upload_image && $image_moved) {

                    $uploaded_path = __DIR__ . "/uploads/" . $image_name;

                    if (file_exists($uploaded_path)) {
                        unlink($uploaded_path);
                    }

                }

            }

            $stmt->close();

        } else {

            $db_error = "Unable to prepare database query.";

        }

    }

}

$products = $conn->query(
    "SELECT * FROM products
     ORDER BY category ASC, product_name ASC"
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>E-Commerce Product Entry</title>

    <link rel="stylesheet" href="style.css">

</head>

<body>

    <h2>E-Commerce Product Entry</h2>

    <p>All fields are required.</p>

    <form action="" method="POST" enctype="multipart/form-data">

        <div class="form-header">
          PLease fill in the form below to add a new product to the inventory.
        </div>

        <div class="form-group">

            <label>Product name</label>

            <input
                type="text"
                name="product_name"
                value="<?php echo htmlspecialchars($product_name); ?>"
            >

            <?php if (isset($errors["product_name"])): ?>

                <p class="error">
                    <?php echo htmlspecialchars($errors["product_name"]); ?>
                </p>

            <?php endif; ?>

        </div>

        <div class="form-group">

            <label>SKU</label>

            <input
                type="text"
                name="sku"
                value="<?php echo htmlspecialchars($sku); ?>"
            >

            <?php if (isset($errors["sku"])): ?>

                <p class="error">
                    <?php echo htmlspecialchars($errors["sku"]); ?>
                </p>

            <?php endif; ?>

        </div>

        <div class="form-group">

            <label>Category</label>

            <select name="category">

                <option value="">Choose an option</option>

                <?php foreach ($categories as $item): ?>

                    <option
                        value="<?php echo htmlspecialchars($item); ?>"
                        <?php if ($category == $item) echo "selected"; ?>
                    >

                        <?php echo htmlspecialchars($item); ?>

                    </option>

                <?php endforeach; ?>

            </select>

            <?php if (isset($errors["category"])): ?>

                <p class="error">
                    <?php echo htmlspecialchars($errors["category"]); ?>
                </p>

            <?php endif; ?>

        </div>

        <div class="form-group">

            <label>Unit price (XAF)</label>

            <input
                type="number"
                name="unit_price"
                step="0.01"
                min="0.01"
                value="<?php echo htmlspecialchars($unit_price); ?>"
            >

            <?php if (isset($errors["unit_price"])): ?>

                <p class="error">
                    <?php echo htmlspecialchars($errors["unit_price"]); ?>
                </p>

            <?php endif; ?>

        </div>

        <div class="form-group">

            <label>Quantity</label>

            <input
                type="number"
                name="quantity"
                min="0"
                step="1"
                value="<?php echo htmlspecialchars($quantity); ?>"
            >

            <?php if (isset($errors["quantity"])): ?>

                <p class="error">
                    <?php echo htmlspecialchars($errors["quantity"]); ?>
                </p>

            <?php endif; ?>

        </div>

        <div class="form-group">

            <label>Description</label>

            <input
                type="text"
                name="description"
                maxlength="1000"
                value="<?php echo htmlspecialchars($description); ?>"
            >

            <?php if (isset($errors["description"])): ?>

                <p class="error">
                    <?php echo htmlspecialchars($errors["description"]); ?>
                </p>

            <?php endif; ?>

        </div>

        <div class="form-group">

            <label>Product image</label>

            <input
                type="file"
                name="product_image"
                accept="image/jpeg,image/png"
            >

            <small>JPEG or PNG only. Maximum size: 2MB.</small>

            <?php if (isset($errors["product_image"])): ?>

                <p class="error">
                    <?php echo htmlspecialchars($errors["product_image"]); ?>
                </p>

            <?php endif; ?>

        </div>

        <div class="form-group">

            <label>Available for sale</label>

            <div>

                <input
                    type="checkbox"
                    name="available"
                    value="1"
                    <?php if ($available == 1) echo "checked"; ?>
                >

                I agree

            </div>

        </div>

        <div class="form-buttons">

            <button type="submit">Submit Form</button>

            <button type="reset">Reset</button>

        </div>

    </form>

    <?php if ($success != ""): ?>

        <script>

            alert(<?php echo json_encode($success); ?>);

        </script>

    <?php endif; ?>

    <?php if ($db_error != ""): ?>

        <p class="error">
            <?php echo htmlspecialchars($db_error); ?>
        </p>

    <?php endif; ?>

    <h2>Registered Products</h2>

    <table>

        <thead>

            <tr>

                <th>Product ID</th>

                <th>Image</th>

                <th>Product Name</th>

                <th>SKU</th>

                <th>Category</th>

                <th>Unit Price (XAF)</th>

                <th>Quantity</th>

                <th>Description</th>

                <th>Available for Sale</th>

                <th>Date Added</th>

            </tr>

        </thead>


        <tbody>

            <?php if ($products && $products->num_rows > 0): ?>

                <?php while ($row = $products->fetch_assoc()): ?>

                    <tr>

                        <td>
                            <?php echo htmlspecialchars($row["product_id"]); ?>
                        </td>


                        <td>

                            <?php if (!empty($row["product_image"])): ?>

                                <img
                                    src="<?php echo htmlspecialchars($row["product_image"]); ?>"
                                    alt="Product image"
                                    class="product-image"
                                >

                            <?php else: ?>

                                No image

                            <?php endif; ?>

                        </td>


                        <td>
                            <?php echo htmlspecialchars($row["product_name"]); ?>
                        </td>


                        <td>
                            <?php echo htmlspecialchars($row["sku"]); ?>
                        </td>


                        <td>
                            <?php echo htmlspecialchars($row["category"]); ?>
                        </td>


                        <td>
                            <?php echo number_format((float)$row["unit_price"], 2); ?>
                        </td>


                        <td>
                            <?php echo htmlspecialchars($row["quantity"]); ?>
                        </td>


                        <td>
                            <?php echo htmlspecialchars($row["description"]); ?>
                        </td>


                        <td>

                            <?php

                            echo $row["available_for_sale"]
                                ? "Yes"
                                : "No";

                            ?>

                        </td>


                        <td>
                            <?php echo htmlspecialchars($row["created_at"]); ?>
                        </td>

                    </tr>

                <?php endwhile; ?>

            <?php else: ?>

                <tr>

                    <td colspan="10">
                        No products have been registered yet.
                    </td>

                </tr>

            <?php endif; ?>

        </tbody>

    </table>


</body>

</html>

<?php

$conn->close();
?>