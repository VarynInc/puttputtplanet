#!/bin/bash
# Script to determine if specific files are different on the server.
echo 'Checking -q'
diff ./services/serverConfig.php <(ssh -i ~/.ssh/varyn-aws.pem ubuntu@ec2-35-167-207-113.us-west-2.compute.amazonaws.com 'cat /var/www/vhosts/puttputtplanet-q/services/serverConfig.php')
echo 'Checking live'
diff ./services/serverConfig.php <(ssh -i ~/.ssh/varyn-aws.pem ubuntu@ec2-35-167-207-113.us-west-2.compute.amazonaws.com 'cat /var/www/vhosts/puttputtplanet/services/serverConfig.php')
echo 'Diff check complete'
