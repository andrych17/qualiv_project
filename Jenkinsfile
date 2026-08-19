pipeline {
    agent any

    // Runs on every checkin to staging (via SCM webhook/polling job config) plus a
    // nightly build, per Simon's request — covers both readings of "nightly build
    // di staging setiap kali ada checkin."
    triggers {
        cron('0 3 * * *') // 03:00 server time
        githubPush()
    }

    environment {
        SHARED_INFRA_NETWORK = 'shared-infra'
        TEST_DB = 'nusaevo_testing'
    }

    stages {
        stage('Start shared-infra (postgres + redis)') {
            steps {
                sh '''
                    docker network create ${SHARED_INFRA_NETWORK} || true
                    docker rm -f ci-postgres ci-redis || true
                    docker run -d --name ci-postgres --network ${SHARED_INFRA_NETWORK} --network-alias postgres \
                        -e POSTGRES_USER=nusaevo -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=nusaevo \
                        postgres:16
                    docker run -d --name ci-redis --network ${SHARED_INFRA_NETWORK} --network-alias redis redis:7
                    for i in $(seq 1 30); do
                        docker exec ci-postgres pg_isready -U nusaevo && break
                        sleep 1
                    done
                    docker exec ci-postgres psql -U nusaevo -d nusaevo -c "CREATE DATABASE ${TEST_DB};"
                '''
            }
        }

        stage('Build app image') {
            steps {
                sh 'docker compose build app'
            }
        }

        stage('Install dependencies') {
            steps {
                sh '''
                    docker compose run --rm app composer install --no-interaction --prefer-dist
                    npm ci
                    npm run build
                '''
            }
        }

        stage('Prepare env') {
            steps {
                sh '''
                    docker compose run --rm app cp .env.example .env
                    docker compose run --rm app php artisan key:generate
                '''
            }
        }

        stage('Migrate test database') {
            steps {
                sh "docker compose run --rm -e DB_DATABASE=${TEST_DB} app php artisan migrate --force"
            }
        }

        stage('Run tests') {
            steps {
                sh 'docker compose run --rm app php artisan test'
            }
        }
    }

    post {
        always {
            sh 'docker rm -f ci-postgres ci-redis || true'
        }
    }
}
