const { now } = require('mongoose')
const pool = require('../../config/db')
const db = require('../../config/db')


class FeedbackController {

    //ham get trong class FeedbackController

    async get(req, res, next) {
        try {
            const query = "SELECT * FROM feedback ORDER BY id DESC"
            const data = await db.query(query)
            const allUsers = data.rows
            res.status(200).json(allUsers)
        } catch (error) {
            res.status(400).json({
                success: false,
                error
            })
        }
    }
    //ham post trong class FeedbackController
    async post(req,res,next) {
       
        let read = false;

        //khai bao request.body

        const {userId,tittle,content,createAt} = req.body;


        // query insert vao table feedback, no nhu sql nen de nho lam
        db.query('INSERT INTO feedback(userid,tittle,content,isread) VALUES ($1, $2, $3, $4 )', 
        [userId,tittle,content,read], (err,results) => {
            // chia thanh 2 truong hop loi va khong loi. Neu loi xay ra thi success: fail tren json
            if(err)
            {
                console.log("khong ton tai userId")
                res.status(401).json({
                    success: false,                  
                    err,
                    
                })

            }
                else res.status(200).json(results.userid)
            })
    }
}


module.exports = new FeedbackController



