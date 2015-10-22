<?= $this->inline("header.php") ?>
<div style="width: 300px">
    <h1 style="text-align: center; font-weight: normal; padding: 50px 0;">Login</h1>

    <style scoped>
        button {
            width: 100%;
            display: block;
            border: 0;
            border-bottom: 2px solid rgba(0, 0, 0, .25);
            font-weight: bold;
            padding: 15px;
            color: rgba(255, 255, 255, .95);
            margin: 8px 0;
            border-radius: 3px;
        }

        button .fa {
            /* best rendering */
            font-size: 14px;
            margin-right: 6px;
        }
    </style>

    <form action="/login/github" method="post">
        <button type="submit" style="background-color: #555">
            <i class="fa fa-github"></i> Sign in with GitHub
        </button>
    </form>

    <form action="/login/stack-exchange" method="post">
        <button type="submit" style="background-color: #195398">
            <i class="fa fa-stack-exchange"></i> Sign in with Stack Exchange
        </button>
    </form>
</div>
<?= $this->inline("footer.php") ?>
