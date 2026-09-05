pipeline {
    agent any

    triggers {
        githubPush()
    }

    stages {
        stage('Deploy to Production') {
            steps {
                sh '''
                    echo "===> Deploying qualiv_project to Production (/opt/qualiv-erp)..."
                    nsenter -t 1 -m -u -n -i bash -c "
                        set -e
                        cd /opt/qualiv-erp
                        if [ ! -d .git ]; then
                            git init
                            git remote add origin https://github.com/andrych17/qualiv_project.git
                        fi
                        git fetch origin main
                        git reset --hard origin/main
                        docker compose run --rm app composer install --no-interaction --prefer-dist --optimize-autoloader
                        npm ci
                        npm run build
                        docker compose run --rm app php artisan migrate --force
                        docker compose restart app queue
                    "
                    echo "===> Deployment completed successfully!"
                '''
            }
        }
    }
}

